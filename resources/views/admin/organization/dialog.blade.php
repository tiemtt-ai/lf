<x-ui-form-dialog :action="route('admin.organization.update')" :title="__('lf.LF_admin_dialog_organization_title')" title-id="dashboard-dialog-title">
    <div class="ui-modal__grid">
        <div class="ui-field ui-field--full">
            <label class="ui-field__label" for="organization-form-name"><span class="ui-field__required" aria-hidden="true">*</span>{{ __('lf.LF_admin_label_tenant_name') }}</label>
            <input id="organization-form-name" type="text" name="name" value="{{ $tenant->name }}" maxlength="255" required
                   placeholder="{{ __('lf.LF_admin_placeholder_organization_name') }}">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="organization-form-email">{{ __('lf.LF_admin_label_tenant_email') }}</label>
            <input id="organization-form-email" type="email" name="email" value="{{ $tenant->email }}" maxlength="255"
                   placeholder="{{ __('lf.LF_admin_placeholder_organization_email') }}">
        </div>
        <div class="ui-field">
            <label class="ui-field__label" for="organization-form-phone">{{ __('lf.LF_admin_label_tenant_phone') }}</label>
            <input id="organization-form-phone" type="text" name="phone" value="{{ $tenant->phone }}" maxlength="30"
                   placeholder="{{ __('lf.LF_admin_placeholder_organization_phone') }}">
        </div>
    </div>
</x-ui-form-dialog>
