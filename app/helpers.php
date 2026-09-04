<?php

if (! function_exists('like_escape')) {
    /**
     * Escape SQL LIKE wildcards so user-supplied search terms are matched literally.
     * Mutations (search/replace) are applied first, status ones last, as per MySQL docs.
     */
    function like_escape(?string $value): string
    {
        $value = $value ?? '';

        $search = ['\\', '%', '_'];
        $replace = ['\\\\', '\\%', '\\_'];

        return str_replace($search, $replace, $value);
    }

    /**
     * Build a safe "%...%" LIKE pattern for an optional search term.
     * Unlike like_escape alone this also wraps the term in wildcards so call sites
     * can replace `"%{$q}%"` with `like_term($q)` without changing matching behaviour.
     */
    function like_term(?string $value): string
    {
        return '%'.like_escape($value).'%';
    }
}