@extends('errors.layout', [
    'code' => 405,
    'title' => __('lf.LF_common_title_public_page_method_not_allowed'),
    'message' => __('lf.LF_common_message_public_page_method_not_allowed'),
    'icon' => 'circle-alert',
    'symbol' => '!',
])
