<?php

namespace Bolt\Controllers;

use Bolt\Application;
use Bolt\Content;
use Bolt\Helpers\Input;
use Bolt\Library as Lib;
use Bolt\Pager;
use Bolt\Translation\Translator as Trans;
use Silex;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use utilphp\util;

/**
 * Standard Frontend actions.
 *
 * This file acts as a grouping for the default front-end controllers.
 *
 * For overriding the default behavior here, please reference
 * http://docs.bolt.cm/templates-routes#routing or the routing.yml
 * file in your configuration.
 */
class Frontend
{
    /**
     * Perform contenttype-based permission check, aborting with a 403
     * Forbidden as appropriate.
     *
     * @param \Silex\Application $app     The application/container
     * @param Content|string     $content The content to check
     */
    protected function checkFrontendPermission(Silex\Application $app, $content)
    {
        if ($app['config']->get('general/frontend_permission_checks')) {
            if ($content instanceof Content) {
                $contenttypeslug = $content->contenttype['slug'];
                $contentid = $content['id'];
            } else {
                $contenttypeslug = (string) $content;
                $contentid = null;
            }
            if (!$app['users']->isAllowed('frontend', $contenttypeslug, $contentid)) {
                $app->abort(403, 'Not allowed.');
            }
        }
    }

    /**
     * The default before filter for the controllers in this file.
     *
     * Refer to the routing.yml config file for overridding.
     *
     * @param Request     $request The Symfony Request
     * @param Application $app     The application/container
     *
     * @return mixed
     */
    public function before(Request $request, Application $app)
    {
        // Start the 'stopwatch' for the profiler.
        $app['stopwatch']->start('bolt.frontend.before');

        // If there are no users in the users table, or the table doesn't exist. Repair
        // the DB, and let's add a new user.
        if (!$app['users']->getUsers()) {
            //!$app['storage']->getIntegrityChecker()->checkUserTableIntegrity() ||
            $app['session']->getFlashBag()->add('info', Trans::__('There are no users in the database. Please create the first user.'));

            return Lib::redirect('useredit', array('id' => ''));
        }

        $app['debugbar'] = true;
        $app['htmlsnippets'] = true;

        // If we are in maintenance mode and current user is not logged in, show maintenance notice.
        // @see /app/app.php, $app->error()
        if ($app['config']->get('general/maintenance_mode')) {
            if (!$app['users']->isAllowed('maintenance-mode')) {
                $template = $app['templatechooser']->maintenance();
                $body = $app['render']->render($template);

                return new Response($body, 503);
            }
        }

        // Stop the 'stopwatch' for the profiler.
        $app['stopwatch']->stop('bolt.frontend.before');
        return null;
    }

    /**
     * Controller for the "Homepage" route. Usually the front page of the website.
     *
     * @param \Silex\Application $app The application/container
     *
     * @return mixed
     */
    public function homepage(Silex\Application $app)
    {
        $content = $app['storage']->getContent($app['config']->get('general/homepage'));

        $template = $app['templatechooser']->homepage();

        if (is_array($content)) {
            $first = $record = current($content);
            $app['twig']->addGlobal('records', $content);
            $app['twig']->addGlobal($first->contenttype['slug'], $content);
        } elseif (!empty($content)) {
            $record = $content;
            $app['twig']->addGlobal('record', $content);
            $app['twig']->addGlobal($content->contenttype['singular_slug'], $content);
        }

        if (!empty($record)) {
            $this->checkFrontendPermission($app, $record);
        }

        return $this->render($app, $template, 'homepage');
    }

    /**
     * Controller for a single record page, like '/page/about/' or '/entry/lorum'.
     *
     * @param \Silex\Application $app             The application/container
     * @param string             $contenttypeslug The content type slug
     * @param string             $slug            The content slug
     *
     * @return mixed
     */
    public function record(Silex\Application $app, $contenttypeslug, $slug = '')
    {
        $contenttype = $app['storage']->getContentType($contenttypeslug);

        // If the contenttype is 'viewless', don't show the record page.
        if (isset($contenttype['viewless']) && $contenttype['viewless'] === true) {
            $app->abort(404, "Page $contenttypeslug/$slug not found.");
        }

        // Perhaps we don't have a slug. Let's see if we can pick up the 'id', instead.
        if (empty($slug)) {
            $slug = $app['request']->get('id');
        }

        $slug = $app['slugify']->slugify($slug);

        // First, try to get it by slug.
        $content = $app['storage']->getContent($contenttype['slug'], array('slug' => $slug, 'returnsingle' => true));

        if (!$content && is_numeric($slug)) {
            // And otherwise try getting it by ID
            $content = $app['storage']->getContent($contenttype['slug'], array('id' => $slug, 'returnsingle' => true));
        }

        $this->checkFrontendPermission($app, $content);

        // No content, no page!
        if (!$content) {
            // There's one special edge-case we check for: if the request is for the backend, without trailing
            // slash and it is intercepted by custom routing, we forward the client to that location.
            if ($slug == trim($app['config']->get('general/branding/path'), '/')) {
                Lib::simpleredirect($app['config']->get('general/branding/path') . '/');
            }
            $app->abort(404, "Page $contenttypeslug/$slug not found.");
        }

        // Then, select which template to use, based on our 'cascading templates rules'
        $template = $app['templatechooser']->record($content);

        // Fallback: If file is not OK, show an error page
        $filename = $app['paths']['templatespath'] . "/" . $template;
        if (!file_exists($filename) || !is_readable($filename)) {
            $error = sprintf(
                "No template for '%s' defined. Tried to use '%s/%s'.",
                $content->getTitle(),
                basename($app['config']->get('general/theme')),
                $template
            );

            // Set/log errors and abort
            $this->setTemplateError($app, $error);
            $app['logger.system']->error($error, array('event' => 'template'));
            $app->abort(404, $error);
        }

        // Setting the canonical path and the editlink.
        $app['canonicalpath'] = $content->link();
        $app['paths'] = $app['resources']->getPaths();
        $app['editlink'] = Lib::path('editcontent', array('contenttypeslug' => $contenttype['slug'], 'id' => $content->id));
        $app['edittitle'] = $content->getTitle();

        // Make sure we can also access it as {{ page.title }} for pages, etc. We set these in the global scope,
        // So that they're also available in menu's and templates rendered by extensions.
        $app['twig']->addGlobal('record', $content);
        $app['twig']->addGlobal($contenttype['singular_slug'], $content);

        // Render the template and return.
        return $this->render($app, $template, $content->getTitle());
    }

    /**
     * The controller for previewing a content from posted data.
     *
     * @param Request            $request         The Symfony Request
     * @param \Silex\Application $app             The application/container
     * @param string             $contenttypeslug The content type slug
     *
     * @return mixed
     */
    public function preview(Request $request, Silex\Application $app, $contenttypeslug)
    {
        $contenttype = $app['storage']->getContentType($contenttypeslug);

        // First, get the preview from Post.
        $content = $app['storage']->getContentObject($contenttypeslug);
        $content->setFromPost($request->request->all(), $contenttype);

        $this->checkFrontendPermission($app, $content);

        // Then, select which template to use, based on our 'cascading templates rules'
        $template = $app['templatechooser']->record($content);

        // Fallback: If file is not OK, show an error page
        $filename = $app['paths']['templatespath'] . "/" . $template;
        if (!file_exists($filename) || !is_readable($filename)) {
            $error = sprintf(
                "No template for '%s' defined. Tried to use '%s/%s'.",
                $content->getTitle(),
                basename($app['config']->get('general/theme')),
                $template
            );

            // Set/log errors and abort
            $this->setTemplateError($app, $error);
            $app['logger.system']->error($error, array('event' => 'template'));
            $app->abort(404, $error);
        }

        // Make sure we can also access it as {{ page.title }} for pages, etc. We set these in the global scope,
        // So that they're also available in menu's and templates rendered by extensions.
        $app['twig']->addGlobal('record', $content);
        $app['twig']->addGlobal($contenttype['singular_slug'], $content);

        // Chrome (unlike Firefox and Internet Explorer) has a feature that helps prevent
        // XSS attacks for uncareful people. It blocks embeds, links and src's that have
        // a URL that's also in the request. In Bolt we wish to enable this type of embeds,
        // because otherwise Youtube, Vimeo and Google Maps embeds will simply not show,
        // causing confusion for the editor, because they don't know what's happening.
        // Is this a security concern, you may ask? I believe it cannot be exploited:
        //   - Disabled, the behaviour on Chrome matches Firefox and IE.
        //   - The user must be logged in to see the 'preview' page at all.
        //   - Our CSRF-token ensures that the user will only see their own posted preview.
        // @see: http://security.stackexchange.com/questions/53474/is-chrome-completely-secure-against-reflected-xss
        header("X-XSS-Protection: 0");

        return $this->render($app, $template, $content->getTitle());
    }

    /**
     * The listing page controller.
     *
     * @param \Silex\Application $app             The application/container
     * @param string             $contenttypeslug The content type slug
     *
     * @return mixed
     */
    public function listing(Silex\Application $app, $contenttypeslug)
    {
        $contenttype = $app['storage']->getContentType($contenttypeslug);

        // If the contenttype is 'viewless', don't show the record page.
        if (isset($contenttype['viewless']) && $contenttype['viewless'] === true) {
            $app->abort(404, "Page $contenttypeslug not found.");
        }

        $pagerid = Pager::makeParameterId($contenttypeslug);
        /* @var $query \Symfony\Component\HttpFoundation\ParameterBag */
        $query = $app['request']->query;
        // First, get some content
        $page = $query->get($pagerid, $query->get('page', 1));
        $amount = (!empty($contenttype['listing_records']) ? $contenttype['listing_records'] : $app['config']->get('general/listing_records'));
        $order = (!empty($contenttype['sort']) ? $contenttype['sort'] : $app['config']->get('general/listing_sort'));
        $content = $app['storage']->getContent($contenttype['slug'], array('limit' => $amount, 'order' => $order, 'page' => $page, 'paging' => true));
        $this->checkFrontendPermission($app, $contenttype['slug']);

        $template = $app['templatechooser']->listing($contenttype);

        // Fallback: If file is not OK, show an error page
        $filename = $app['paths']['templatespath'] . "/" . $template;
        if (!file_exists($filename) || !is_readable($filename)) {
            $error = sprintf(
                "No template for '%s'-listing defined. Tried to use '%s/%s'.",
                $contenttypeslug,
                basename($app['config']->get('general/theme')),
                $template
            );

            // Set/log errors and abort
            $this->setTemplateError($app, $error);
            $app['logger.system']->error($error, array('event' => 'template'));
            $app->abort(404, $error);
        }

        // Make sure we can also access it as {{ pages }} for pages, etc. We set these in the global scope,
        // So that they're also available in menu's and templates rendered by extensions.
        $app['twig']->addGlobal('records', $content);
        $app['twig']->addGlobal($contenttype['slug'], $content);
        $app['twig']->addGlobal('contenttype', $contenttype['name']);

        return $this->render($app, $template, $contenttypeslug);
    }

    /**
     * The taxonomy listing page controller.
     *
     * @param \Silex\Application $app          The application/container
     * @param string             $taxonomytype The taxonomy type slug
     * @param string             $slug         The taxonomy slug
     *
     * @return mixed
     */
    public function taxonomy(Silex\Application $app, $taxonomytype, $slug)
    {
        $taxonomy = $app['storage']->getTaxonomyType($taxonomytype);
        // No taxonomytype, no possible content.
        if (empty($taxonomy)) {
            return false;
        } else {
            $taxonomyslug = $taxonomy['slug'];
        }
        // First, get some content
        $context = $taxonomy['singular_slug'] . '_' . $slug;
        $pagerid = Pager::makeParameterId($context);
         /* @var $query \Symfony\Component\HttpFoundation\ParameterBag */
        $query = $app['request']->query;
        $page = $query->get($pagerid, $query->get('page', 1));
        $amount = $app['config']->get('general/listing_records');
        $order = $app['config']->get('general/listing_sort');
        $content = $app['storage']->getContentByTaxonomy($taxonomytype, $slug, array('limit' => $amount, 'order' => $order, 'page' => $page));

        // See https://github.com/bolt/bolt/pull/2310
        if (($taxonomy['behaves_like'] === 'tags' && !$content)
            || (in_array($taxonomy['behaves_like'], array('categories', 'grouping')) && !in_array($slug, isset($taxonomy['options']) ? array_keys($taxonomy['options']) : array()))) {
            $app->abort(404, "No slug '$slug' in taxonomy '$taxonomyslug'");
        }

        $template = $app['templatechooser']->taxonomy($taxonomyslug);

        // Fallback: If file is not OK, show an error page
        $filename = $app['resources']->getPath('templatespath') . '/' . $template;

        if (!file_exists($filename) || !is_readable($filename)) {
            $error = sprintf(
                "No template for '%s'-listing defined. Tried to use '%s/%s'.",
                $taxonomyslug,
                basename($app['config']->get('general/theme')),
                $template
            );

            // Set/log errors and abort
            $this->setTemplateError($app, $error);
            $app['logger.system']->error($error, array('event' => 'template'));
            $app->abort(404, $error);
        }

        $name = $slug;
        // Look in taxonomies in 'content', to get a display value for '$slug', perhaps.
        foreach ($content as $record) {
            $flat = util::array_flatten($record->taxonomy);
            $key = $app['paths']['root'] . $taxonomy['slug'] . '/' . $slug;
            if (isset($flat[$key])) {
                $name = $flat[$key];
            }
            $key = $app['paths']['root'] . $taxonomy['singular_slug'] . '/' . $slug;
            if (isset($flat[$key])) {
                $name = $flat[$key];
            }
        }

        $app['twig']->addGlobal('records', $content);
        $app['twig']->addGlobal('slug', $name);
        $app['twig']->addGlobal('taxonomy', $app['config']->get('taxonomy/' . $taxonomyslug));
        $app['twig']->addGlobal('taxonomytype', $taxonomyslug);

        return $this->render($app, $template, $taxonomyslug);
    }

    /**
     * The search result page controller.
     *
     * @param Request            $request The Symfony Request
     * @param \Silex\Application $app     The application/container
     *
     * @return mixed
     */
    public function search(Request $request, Silex\Application $app)
    {
        $q = '';
        $context = __FUNCTION__;

        if ($request->query->has('q')) {
            $q = $request->get('q');
        } elseif ($request->query->has($context)) {
            $q = $request->get($context);
        }
        $q = Input::cleanPostedData($q, false);

        $param = Pager::makeParameterId($context);
        /* @var $query \Symfony\Component\HttpFoundation\ParameterBag */
        $query = $request->query;
        $page = ($query) ? $query->get($param, $query->get('page', 1)) : 1;

        $config = $app['config'];
        $pageSize = $config->get('general/search_results_records') ?: ($config->get('general/listing_records') ?: 10);

        $offset = ($page - 1) * $pageSize;
        $limit = $pageSize;

        // set-up filters from URL
        $filters = array();
        foreach ($request->query->all() as $key => $value) {
            if (strpos($key, '_') > 0) {
                list($contenttypeslug, $field) = explode('_', $key, 2);
                if (isset($filters[$contenttypeslug])) {
                    $filters[$contenttypeslug][$field] = $value;
                } else {
                    $contenttype = $app['storage']->getContentType($contenttypeslug);
                    if (is_array($contenttype)) {
                        $filters[$contenttypeslug] = array(
                            $field => $value
                        );
                    }
                }
            }
        }
        if (count($filters) == 0) {
            $filters = null;
        }

        $result = $app['storage']->searchContent($q, null, $filters, $limit, $offset);

        $pager = array(
            'for'          => $context,
            'count'        => $result['no_of_results'],
            'totalpages'   => ceil($result['no_of_results'] / $pageSize),
            'current'      => $page,
            'showing_from' => $offset + 1,
            'showing_to'   => $offset + count($result['results']),
            'link'         => '/search?q=' . rawurlencode($q) . '&page_search='
        );

        $app['storage']->setPager($context, $pager);

        $app['twig']->addGlobal('records', $result['results']);
        $app['twig']->addGlobal($context, $result['query']['use_q']);
        $app['twig']->addGlobal('searchresult', $result);

        $template = $app['templatechooser']->search();

        return $this->render($app, $template, 'search');
    }

    /**
     * Renders the specified template from the current theme in response to a request without
     * loading any content.
     *
     * @param \Silex\Application $app      The application/container
     * @param string             $template The template name
     *
     * @throws \Exception
     *
     * @return mixed
     */
    public function template(Silex\Application $app, $template)
    {
        // Add the template extension if it is missing
        if (!preg_match('/\\.twig$/i', $template)) {
            $template .= '.twig';
        }

        return $this->render($app, $template, $template);
    }

    /**
     * Render a template while wrapping Twig_Error_Loader in 404
     * in case the template is not found by Twig.
     *
     * @param \Silex\Application $app
     * @param string             $template Ex: 'listing.twig'
     * @param string             $title    '%s' in "No template for '%s' defined."
     *
     * @return mixed Rendered template
     */
    private function render(Silex\Application $app, $template, $title)
    {
        try {
            return $app['twig']->render($template);
        } catch (\Twig_Error_Loader $e) {
            $error = sprintf(
                "No template for '%s' defined. Tried to use '%s/%s'.",
                $title,
                basename($app['config']->get('general/theme')),
                $template
            );

            $app['logger.system']->error($error, array('event' => 'twig'));

            // Abort ship
            $app->abort(404, $error);
        }
    }

    /**
     * Set the TwigDataCollector templatechosen parameter if enabled.
     *
     * @param \Silex\Application $app
     * @param string             $error
     */
    private function setTemplateError(Silex\Application $app, $error)
    {
        if (isset($app['twig.logger'])) {
            $app['twig.logger']->setTrackedValue('templateerror', $error);
        }
    }
}
