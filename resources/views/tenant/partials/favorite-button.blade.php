{{-- "Quan tâm" toggle of a Course Product (ADR-0030 D4). A plain form that works without script; student-site.js
     sends it in the background and flips every button of the same Product on the page. --}}
@php $labeled ??= false; @endphp
<form method="POST" class="student-favorite" data-favorite-form
      action="{{ route($favorite ? 'student.courses.favorites.destroy' : 'student.courses.favorites.store', $productId) }}"
      data-add-url="{{ route('student.courses.favorites.store', $productId) }}"
      data-remove-url="{{ route('student.courses.favorites.destroy', $productId) }}">
    @csrf
    <input type="hidden" name="_method" value="{{ $favorite ? 'DELETE' : 'POST' }}" data-favorite-method>
    <button type="submit" @class(['student-products__like', 'is-liked' => $favorite, 'is-labeled' => $labeled])
            data-favorite="{{ $productId }}" aria-pressed="{{ $favorite ? 'true' : 'false' }}"
            data-label-add="{{ __('lf.LF_my_learning_favorite_add') }}" data-label-remove="{{ __('lf.LF_my_learning_favorite_remove') }}"
            data-label-failed="{{ __('lf.LF_my_learning_favorite_failed') }}"
            aria-label="{{ __($favorite ? 'lf.LF_my_learning_favorite_remove' : 'lf.LF_my_learning_favorite_add') }}"
            title="{{ __($favorite ? 'lf.LF_my_learning_favorite_remove' : 'lf.LF_my_learning_favorite_add') }}">
        @if ($labeled)
            <span data-favorite-text>{{ __($favorite ? 'lf.LF_my_learning_favorite_remove' : 'lf.LF_my_learning_favorite_add') }}</span>
        @endif
    </button>
</form>
