@props(['title', 'text', 'keep', 'discard'])

{{-- Nested confirmation for a dialog form with unsaved changes (behaviour: admin-ui-kit-dialog-form.js). --}}
<dialog class="ui-modal" data-columns="1" aria-labelledby="ui-dialog-discard-title" data-ui-dialog-form-confirm>
    <div class="ui-modal__form">
        <header class="ui-modal__header">
            <h3 id="ui-dialog-discard-title">{{ $title }}</h3>
        </header>
        <div class="ui-modal__body">
            <p>{{ $text }}</p>
        </div>
        <footer class="ui-modal__footer">
            <button type="button" class="ui-button cursor-interaction" data-ui-dialog-form-keep>{{ $keep }}</button>
            <button type="button" class="ui-button ui-button--primary cursor-interaction" data-ui-dialog-form-discard>{{ $discard }}</button>
        </footer>
    </div>
</dialog>
