<?php

namespace Bolt\Controller;

use Bolt\Storage\Entity\Content;

trait HierarchicalContentTrait
{

    private $pathPieces = [];
    private $parentTree = [];
    private $lastResult = false;
    private $routePrefix = '/';

    private function getPathArray($slug)
    {

        $slug             = trim($slug, '/');
        $this->pathPieces = explode('/', $slug);

        return $this->pathPieces;
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

    public function getChildContent($contentType, $parentId, $hydrate = false, $order = 'datepublish')
    {

        return $this->getContentWrapper($contentType, [
            'parent'       => $parentId,
            'order'        => $order,
            'returnsingle' => false,
            'hydrate'      => $hydrate
        ]);
    }

    private function getHierarchicalContent($contentType, $slug, $hydrate = false)
    {

        $this->getPathArray($slug);

        // This used to be = ''; but changed to null due to how the DB was behaving
        $lastParentId = null;

        if (count($this->pathPieces)) {
            foreach ($this->pathPieces as $slug) {
                /**
                 * @var Content $result
                 */
                $result = $this->getContentBySlugAndParent($contentType, $slug, $lastParentId, $hydrate);

                if (!$result instanceof Content && is_numeric($slug)) {
                    /**
                     * @var Content $result
                     */
                    $result = $this->getContentByIdAndParent($contentType, $slug, $lastParentId, $hydrate);
                }

                if ($result instanceof Content) {
                    $this->parentTree[] = [
                        'id'   => $result['id'],
                        'slug' => $slug
                    ];

                    $this->lastResult = $result;

                    $lastParentId = $result['id'];
                } else {
                    // Parent page doesn't exist, the URL is invalid -> Clear parent_tree
                    $this->parentTree = [];
                    $this->lastResult = false;

                    // Don't carry on with the loop, the URL is invalid
                    break;
                }
            }
        }

        return $this->lastResult;
    }

    public function getHierarchicalPathArray($contentType, $slug, $hydrate = false)
    {

        if (is_object($slug) && $slug instanceof Content) {
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
            $this->pathPieces[] = $result->offsetGet('slug');
            $parentId           = $result->offsetGet('parent');

            if (!empty($parentId)) {
                $this->pathPieces = $this->getHierarchicalPathArray($contentType, $parentId, $hydrate);
            }
        }

        $pathPieces       = $this->pathPieces;
        $this->pathPieces = [];

        return $pathPieces;
    }

    public function getHierarchicalIDArray($contentType, $slug, $hydrate = false)
    {

        if (is_object($slug) && $slug instanceof Content) {
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
            $this->pathPieces[] = $result->offsetGet('id');
            $parentId           = $result->offsetGet('parent');

            if (!empty($parentId)) {
                $this->pathPieces = $this->getHierarchicalIDArray($contentType, $parentId, $hydrate);
            }
        }

        $pathPieces       = $this->pathPieces;
        $this->pathPieces = [];

        return $pathPieces;
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
            $resultParent = $result->offsetGet('parent');
            $resultSlug   = $result->offsetGet('slug');

            $this->routePrefix = '/' . $resultSlug . $this->routePrefix;

            if ($result->offsetGet('parent')) {
                return $this->getRoutePrefix($contentType, $resultParent, $hydrate);
            } else {
                $routePrefix = $this->routePrefix;

                return $routePrefix;
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
