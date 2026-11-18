@extends('errors.layout', [
    'code' => 403,
    'title' => __('lf.LF_common_title_public_page_forbidden'),
    'message' => __('lf.LF_common_message_public_page_forbidden'),
    'icon' => 'lock',
    'symbol' => '!',
])
