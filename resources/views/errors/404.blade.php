@extends('errors.layout', [
    'code' => 404,
    'title' => __('lf.LF_common_title_public_page_not_found'),
    'message' => __('lf.LF_common_message_public_page_not_found'),
    'icon' => 'search',
    'symbol' => '?',
])
