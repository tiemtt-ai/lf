{{-- One judgment for one student on the current competency. Typing a score selects the level its
     thresholds give and locks the others, because the service refuses a score that contradicts the
     chosen level. Clearing the score unlocks every level. --}}
<form method="POST" action="{{ route('teacher.cohorts.judgments.store', $cohort->id) }}"
      class="teacher-judgment__form"
      aria-label="{{ __('lf.LF_teacher_judgment_form_label', ['name' => $student->name]) }}"
      x-data="{
          levels: @js($thresholds),
          level: @js($isOld ? old('level') : null),
          score: @js($isOld ? old('score_percent') : ''),
          get matched() {
              if (this.score === '' || this.score === null || isNaN(Number(this.score))) return null;
              const value = Number(this.score) / 100;
              let picked = null;
              this.levels.forEach((l) => { if (value >= l.threshold) picked = l.key; });
              return picked;
          },
          fromScore() {
              if (this.matched) this.level = this.matched;
          },
      }">
    @csrf
    <input type="hidden" name="submission_uuid" value="{{ $isOld ? old('submission_uuid') : (string) \Illuminate\Support\Str::uuid() }}">
    <input type="hidden" name="membership_id" value="{{ $student->id }}">
    <input type="hidden" name="basis_id" value="{{ $basis->id }}">
    <input type="hidden" name="node_id" value="{{ $node->id }}">

    <fieldset class="teacher-judgment__levels">
        <legend class="lf-form-label">
            {{ __('lf.LF_teacher_judgment_level') }} <span class="lf-required-indicator" aria-hidden="true">*</span>
            <x-info-tip :text="__('lf.LF_teacher_judgment_level_tip')" />
        </legend>
        <div class="teacher-judgment__level-options">
            @foreach ($levels as $level)
                <label class="teacher-judgment__level-option"
                       :class="{ 'is-selected': level === @js($level['key']), 'is-locked': matched && matched !== @js($level['key']) }">
                    <input type="radio" name="level" value="{{ $level['key'] }}" x-model="level" required
                           :disabled="matched && matched !== @js($level['key'])">
                    <span>{{ $levelLabel($level['key']) }}</span>
                    @if ((float) $level['threshold'] > 0)
                        <span class="teacher-judgment__threshold">≥ {{ round((float) $level['threshold'] * 100) }}%</span>
                    @endif
                </label>
            @endforeach
        </div>
    </fieldset>

    <div class="teacher-judgment__fields">
        <div class="lf-form-group">
            <label class="lf-form-label" for="{{ $formId }}-score">
                {{ __('lf.LF_teacher_judgment_score') }}
                <x-info-tip :text="__('lf.LF_teacher_judgment_score_tip')" />
            </label>
            <input id="{{ $formId }}-score" name="score_percent" type="number" min="0" max="100" step="1" inputmode="numeric"
                   class="lf-form-control" x-model="score" x-on:input="fromScore()">
        </div>
        <div class="lf-form-group">
            <label class="lf-form-label" for="{{ $formId }}-occurred">
                {{ __('lf.LF_teacher_judgment_occurred_at') }} <span class="lf-required-indicator" aria-hidden="true">*</span>
                <x-info-tip :text="__('lf.LF_teacher_judgment_occurred_at_tip')" align="end" />
            </label>
            <input id="{{ $formId }}-occurred" name="occurred_at" type="datetime-local" class="lf-form-control" required
                   value="{{ $isOld ? old('occurred_at') : $nowLocal }}" max="{{ $nowLocal }}" min="{{ $studentMinLocal }}">
        </div>
    </div>

    <div class="lf-form-group">
        <label class="lf-form-label" for="{{ $formId }}-reason">
            {{ __('lf.LF_teacher_judgment_reason') }} <span class="lf-required-indicator" aria-hidden="true">*</span>
            <x-info-tip :text="__('lf.LF_teacher_judgment_reason_tip')" />
        </label>
        <textarea id="{{ $formId }}-reason" name="reason" class="lf-form-control" rows="3" maxlength="5000" required>{{ $isOld ? old('reason') : '' }}</textarea>
    </div>

    <p class="lf-form-help">{{ __('lf.LF_teacher_judgment_history_note') }}</p>
    <div class="teacher-judgment__form-actions">
        <button type="submit" class="btn btn-primary">{{ __('lf.LF_teacher_judgment_save') }}</button>
    </div>
</form>
