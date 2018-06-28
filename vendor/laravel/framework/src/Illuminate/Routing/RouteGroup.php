<?php

namespace Illuminate\Routing;

use Illuminate\Support\Arr;

class RouteGroup
{
    /**
     * Merge route groups into a new array.
     *
     * @param  array  $new
     * @param  array  $old
     * @return array
     */
    public static function merge($new, $old)
    {
        if (isset($new['domain'])) {
            unset($old['domain']);
        }
        
        //重新生成当前属性
        $new = array_merge(static::formatAs($new, $old), [
            'namespace' => static::formatNamespace($new, $old),
            'prefix' => static::formatPrefix($new, $old),
            'where' => static::formatWhere($new, $old),
        ]);
        
        //除了['namespace', 'prefix', 'where', 'as']，其他属性使用父属性
        return array_merge_recursive(Arr::except(
            $old, ['namespace', 'prefix', 'where', 'as']
        ), $new);
    }

    /**
     * Format the namespace for the new group attributes.
     * <br>格式化namespace
     * @param  array  $new
     * @param  array  $old
     * @return string|null
     */
    protected static function formatNamespace($new, $old)
    {   
        //命名空间拼接
        if (isset($new['namespace'])) {
            return isset($old['namespace'])
                    ? trim($old['namespace'], '\\').'\\'.trim($new['namespace'], '\\')
                    : trim($new['namespace'], '\\');
        }

        return $old['namespace'] ?? null;
    }

    /**
     * Format the prefix for the new group attributes.
     * <br>格式化prefix
     * @param  array  $new
     * @param  array  $old
     * @return string|null
     */
    protected static function formatPrefix($new, $old)
    {
        $old = $old['prefix'] ?? null;
        //前缀拼接
        return isset($new['prefix']) ? trim($old, '/').'/'.trim($new['prefix'], '/') : $old;
    }

    /**
     * Format the "wheres" for the new group attributes.
     * <br>格式化wheres
     * @param  array  $new
     * @param  array  $old
     * @return array
     */
    protected static function formatWhere($new, $old)
    {
        //where属性合并
        return array_merge(
            $old['where'] ?? [],
            $new['where'] ?? []
        );
    }

    /**
     * Format the "as" clause of the new group attributes.
     * <br>格式化as
     * @param  array  $new
     * @param  array  $old
     * @return array
     */
    protected static function formatAs($new, $old)
    {
        if (isset($old['as'])) {
            //如果父属性有as，则进行拼接
            $new['as'] = $old['as'].($new['as'] ?? '');
        }

        return $new;
    }
}
