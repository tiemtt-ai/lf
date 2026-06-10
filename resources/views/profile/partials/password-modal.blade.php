@props([
    'action',
    'name' => 'change-password',
])

<x-modal :name="$name" :show="$errors->updatePassword->any()" focusable>
    <div class="lf-modal-card">
        <h2>Change Password</h2>

        @if ($errors->updatePassword->any())
            <div class="lf-alert-danger">
                <ul>
                    @foreach ($errors->updatePassword->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf
            @method('PATCH')

            <div class="lf-form-group">
                <label class="lf-form-label">Current Password</label>
                <input type="password" name="current_password" class="lf-form-control"
                       autocomplete="current-password" required>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label">New Password</label>
                <input type="password" name="password" class="lf-form-control"
                       autocomplete="new-password" required>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label">Confirm New Password</label>
                <input type="password" name="password_confirmation" class="lf-form-control"
                       autocomplete="new-password" required>
            </div>

            <div class="lf-modal-actions">
                <button type="button" class="lf-btn-secondary"
                        x-on:click="$dispatch('close-modal', '{{ $name }}')">
                    Cancel
                </button>
                <button type="submit" class="lf-btn-primary">Change Password</button>
            </div>
        </form>
    </div>
</x-modal>
