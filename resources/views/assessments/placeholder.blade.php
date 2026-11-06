@extends('layouts.backend')

@section('title', __('lf.LF_navigation_menu_assessment_'.$section))
@section('page_title', __('lf.LF_navigation_menu_assessment_'.$section))

@section('page_title_hidden', '1')

@section('vite')
    @parent
    @vite(['resources/css/admin/admin-ui-kit.css'])
@endsection

@section('content')
<div class="lf-admin-ui lf-admin-ui--list" data-assessment-section="{{ $section }}">
    <x-ui-list-head :title="__('lf.LF_navigation_menu_assessment_'.$section)" :text="__('lf.LF_assessment_'.$section.'_help')" />

    <section class="mk-section" aria-labelledby="admin-page-title">
        <div class="mk-table-scroll">
        <table aria-labelledby="admin-page-title">
            <tbody>
                <tr class="mk-empty-row">
                    <td class="mk-empty">
                        <div role="status">
                            <strong>{{ __('lf.LF_assessment_placeholder_title') }}</strong>
                            <p class="ui-caption">{{ __('lf.LF_assessment_placeholder_help') }}</p>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    </section>
</div>
@endsection
