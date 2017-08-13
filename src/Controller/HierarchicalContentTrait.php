<?php

namespace Bolt\Controller;

use Bolt\Storage\Entity\Content;

trait HierarchicalContentTrait
{

    private $path_pieces = [];
    private $parent_tree = [];
    private $last_result = false;
    private $route_prefix = '/';

    private function getPathArray($slug)
    {

        $slug              = trim($slug, '/');
        $this->path_pieces = explode('/', $slug);

        return $this->path_pieces;
    }

    private function getContentWrapper($contentType, $params = [], $additionalParams = [])
    {

        return $this->app['storage']->getContent($contentType, $params, $this->app['pager'], $additionalParams);
    }

    private function getContentByIdAndParent($contentType, $id, $parent, $hydrate = false)
    {

        $id = $this->app['slugify']->slugify($id);

        return $this->getContentWrapper($contentType, [
            'id'            => $id,
            'returnsingle'  => true,
            'log_not_found' => !is_numeric($id),
            'hydrate'       => $hydrate
        ], ['parent' => $parent]);
    }

    private function getContentBySlugAndParent($contentType, $slug, $parent, $hydrate = false)
    {

        $slug = $this->app['slugify']->slugify($slug);

        return $this->getContentWrapper($contentType, [
            'slug'          => $slug,
            'returnsingle'  => true,
            'log_not_found' => !is_numeric($slug),
            'hydrate'       => $hydrate
        ], ['parent' => $parent]);
    }

    private function getContentById($contentType, $id, $hydrate = false)
    {

        $id = $this->app['slugify']->slugify($id);

        return $this->getContentWrapper($contentType, [
            'id'            => $id,
            'returnsingle'  => true,
            'log_not_found' => !is_numeric($id),
            'hydrate'       => $hydrate
        ]);
    }

    private function getContentBySlug($contentType, $slug, $hydrate = false)
    {

        $slug = $this->app['slugify']->slugify($slug);

        return $this->getContentWrapper($contentType, [
            'slug'          => $slug,
            'returnsingle'  => true,
            'log_not_found' => !is_numeric($slug),
            'hydrate'       => $hydrate
        ]);
    }

    public function getChildContent($contentType, $parent_id, $hydrate = false, $order = 'datepublish')
    {

        return $this->getContentWrapper($contentType, [
            'parent'       => $parent_id,
            'order'        => $order,
            'returnsingle' => false,
            'hydrate'      => $hydrate
        ]);
    }

    private function getHierarchicalContent($contentType, $slug, $hydrate = false)
    {

        $this->getPathArray($slug);

        // This used to be = ''; but changed to null due to how the DB was behaving
        $last_parent_id = null;

        if (count($this->path_pieces)) {
            foreach ($this->path_pieces as $slug) {
                /**
                 * @var Content $result
                 */
                $result = $this->getContentBySlugAndParent($contentType, $slug, $last_parent_id, $hydrate);

                if (!$result instanceof Content && is_numeric($slug)) {
                    /**
                     * @var Content $result
                     */
                    $result = $this->getContentByIdAndParent($contentType, $slug, $last_parent_id, $hydrate);
                }

                if ($result instanceof Content) {
                    $this->parent_tree[] = [
                        'id'   => $result['id'],
                        'slug' => $slug
                    ];

                    $this->last_result = $result;

                    $last_parent_id = $result['id'];
                } else {
                    // Parent page doesn't exist, the URL is invalid -> Clear parent_tree
                    $this->parent_tree = [];
                    $this->last_result = false;

                    // Don't carry on with the loop, the URL is invalid
                    break;
                }
            }
        }

        return $this->last_result;
    }

    public function getHierarchicalPathArray($contentType, $slug, $hydrate = false)
    {

        if (is_object($slug)) {
            /**
             * @var Content $result
             */
            $result = $slug;
        } else {
            /**
             * @var Content $result
             */
            $result = $this->getContentBySlug($contentType, $slug, $hydrate);
        }

        if (!$result instanceof Content && is_numeric($slug)) {
            /**
             * @var Content $result
             */
            $result = $this->getContentById($contentType, $slug, $hydrate);
        }

        if ($result instanceof Content) {
            $this->path_pieces[] = $result->offsetGet('slug');
            $parent_id           = $result->offsetGet('parent');

            if (!empty($parent_id)) {
                $this->path_pieces = $this->getHierarchicalPathArray($contentType, $parent_id, $hydrate);
            }
        }

        $path_pieces       = $this->path_pieces;
        $this->path_pieces = [];

        return $path_pieces;
    }

    public function getHierarchicalIDArray($contentType, $slug, $hydrate = false)
    {

        if (is_object($slug)) {
            /**
             * @var Content $result
             */
            $result = $slug;
        } else {
            /**
             * @var Content $result
             */
            $result = $this->getContentBySlug($contentType, $slug, $hydrate);
        }

        if (!$result instanceof Content && is_numeric($slug)) {
            /**
             * @var Content $result
             */
            $result = $this->getContentById($contentType, $slug, $hydrate);
        }

        if ($result instanceof Content) {
            $this->path_pieces[] = $result->offsetGet('id');
            $parent_id           = $result->offsetGet('parent');

            if (!empty($parent_id)) {
                $this->path_pieces = $this->getHierarchicalIDArray($contentType, $parent_id, $hydrate);
            }
        }

        $path_pieces       = $this->path_pieces;
        $this->path_pieces = [];

        return $path_pieces;
    }

    public function getHierarchicalPath($contentType, $content, $hydrate = false)
    {

        $path   = $this->getHierarchicalPathArray($contentType, $content, $hydrate);
        $prefix = $this->app['config']->get('contenttypes/' . $contentType . '/hierarchical_prefix');

        if (is_array($path) && count($path)) {
            $path = trim(implode('/', array_reverse($path)), '/');

            if ($path !== '' || $path !== '/') {
                $path = '/' . $path;
            }

            if (is_string($prefix) && $prefix !== '') {
                $path = '/' . trim($prefix, '/') . $path;
            }

            if ($path !== '/') {
                $path .= '/';
            }

            return $path;
        }

        return '/';
    }

    public function getRootParentSlug($contentType, $slug, $hydrate = false)
    {

        $parent = false;
        $path   = $this->getHierarchicalPathArray($contentType, $slug, $hydrate);

        if (is_array($path) && count($path)) {
            $parent = array_pop($path);
        }

        return $parent;
    }

    public function getRootParentID($contentType, $slug, $hydrate = false)
    {

        $parent = false;
        $path   = $this->getHierarchicalIDArray($contentType, $slug, $hydrate);

        if (is_array($path) && count($path)) {
            $parent = array_pop($path);
        }

        return $parent;
    }

    public function getRoutePrefix($contentType, $parent, $hydrate = false)
    {

        /**
         * @var Content $result
         */
        $result = $this->getContentById($contentType, $parent, $hydrate);

        if ($result instanceof Content) {
            $result_parent = $result->offsetGet('parent');
            $result_slug   = $result->offsetGet('slug');

            $this->route_prefix = '/' . $result_slug . $this->route_prefix;

            if ($result->offsetGet('parent')) {
                return $this->getRoutePrefix($contentType, $result_parent, $hydrate);
            } else {
                $route_prefix = $this->route_prefix;

                return $route_prefix;
            }
        }
    }

    public function getAllHierarchies($contentType, $bySlug = true, $hydrate = false)
    {

        $cacheKey = '_hierarchies_' . $contentType;

        if ($this->app['cache']->contains($cacheKey)) {
            $contents = json_decode($this->app['cache']->fetch($cacheKey), true);
        } else {
            $contents = $this->getContentWrapper($contentType, [
                'returnsingle'  => false,
                'log_not_found' => false,
                'hydrate'       => $hydrate
            ]);
            $this->app['cache']->save($cacheKey, json_encode($contents));
        }

        $hierarchy = [];

        if (is_array($contents) && count($contents)) {
            foreach ($contents as $content) {
                if (is_array($content)) {
                    $content = $this->fillContent($contentType, $content);
                }

                $path = $this->getHierarchicalPath($contentType, $content, $hydrate);

                if ($bySlug) {
                    $key = $content->get('slug');
                } else {
                    $key = $content->get('id');
                }

                $hierarchy[$key] = [
                    'key'    => $key,
                    'path'   => $path,
                    'prefix' => $prefix = $this->app['config']->get('contenttypes/' . $contentType . '/hierarchical_prefix')
                ];
            }
        }

        return $this->sortByKeyAndParent($hierarchy);
    }

    private function sortByKeyAndParent($array, $asc = true)
    {

        usort($array, function ($a, $b) {

            return strnatcmp($a['path'], $b['path']);
        });

        if (!$asc) {
            return array_reverse($array);
        }

        return $array;
    }

    private function fillContent($contentType, $content)
    {

        $array = $content;

        /**
         * @var Content $content
         */
        $content = $this->app['storage']->getContentObject($contentType, $content);

        foreach ($array['values'] as $k => $v) {
            $content->offsetSet($k, $v);
        }

        return $content;
    }
}
