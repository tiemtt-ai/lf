@extends('layouts.backend')

@section('title', __('lf.LF_teacher_judgment_title', ['cohort' => $cohort->name]))
@section('page_title', __('lf.LF_teacher_judgment_title', ['cohort' => $cohort->name]))

@php
    $canJudge = $cohort->blocked_reason === null && $basis !== null;
    $levels = collect($basis->levels ?? [])->values();
    $levelLabel = fn (string $key) => \Illuminate\Support\Facades\Lang::has('lf.LF_learning_simple_level_'.$key)
        ? __('lf.LF_learning_simple_level_'.$key)
        : $key;
    $tone = function (?string $key) use ($levels): string {
        $index = $key === null ? -1 : $levels->search(fn ($level) => $level['key'] === $key);

        return match (true) {
            $index === false || $index < 0 => 'none',
            $index === 0 => 'start',
            $index === $levels->count() - 1 => 'top',
            $index >= ($levels->count() - 1) / 2 => 'good',
            default => 'mid',
        };
    };
    $mapped = $competencies->where('mapped', true);
    $others = $competencies->where('mapped', false);
    $nowLocal = now()->format('Y-m-d\TH:i');
    $minLocal = $cohort->start_date ? \Illuminate\Support\Carbon::parse($cohort->start_date)->format('Y-m-d\T00:00') : null;
    $thresholds = $levels->map(fn ($level) => ['key' => $level['key'], 'threshold' => (float) $level['threshold']])->values();
@endphp

@section('content')
    <div class="teacher-judgment">
        <div class="teacher-judgment__header">
            <span class="teacher-judgment__meta">
                {{ $cohort->product_title }}
                @if ($cohort->start_date && $cohort->end_date)
                    · {{ \Illuminate\Support\Carbon::parse($cohort->start_date)->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($cohort->end_date)->format('d/m/Y') }}
                @endif
                · {{ trans_choice('lf.LF_teacher_cohorts_students', $students->count(), ['count' => $students->count()]) }}
            </span>
            @if ($cohort->bases->count() > 1)
                <form method="GET" class="teacher-judgment__basis-form">
                    <label class="lf-form-label" for="teacher-judgment-basis">{{ __('lf.LF_teacher_judgment_basis') }}</label>
                    <select id="teacher-judgment-basis" name="basis" class="lf-form-control" onchange="this.form.submit()">
                        @foreach ($cohort->bases as $option)
                            <option value="{{ $option->id }}" @selected($basis && $option->id === $basis->id)>{{ __('lf.LF_teacher_cohorts_basis', ['framework' => $option->framework_name, 'number' => $option->version_number]) }}</option>
                        @endforeach
                    </select>
                </form>
            @elseif ($basis)
                <span class="badge badge-info teacher-judgment__basis">
                    {{ __('lf.LF_teacher_cohorts_basis', ['framework' => $basis->framework_name, 'number' => $basis->version_number]) }}
                </span>
            @endif
        </div>

        @if ($errors->any())
            <div class="admin-alert admin-alert-danger" role="alert">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @if (session('success'))
            <div class="admin-alert admin-alert-success" role="status">{{ session('success') }}</div>
        @endif
        @if ($cohort->blocked_reason)
            <div class="admin-alert admin-alert-warning" role="status">{{ __('lf.LF_teacher_cohorts_blocked_'.$cohort->blocked_reason) }}</div>
        @endif

        @if ($basis === null)
            <div class="admin-card admin-form-empty-state" role="status">
                <strong>{{ __('lf.LF_teacher_cohorts_blocked_no_competencies') }}</strong>
            </div>
        @else
            <div class="teacher-judgment__layout">
                <aside class="admin-card teacher-judgment__skills" aria-labelledby="teacher-judgment-skills-title">
                    <h2 id="teacher-judgment-skills-title" class="teacher-judgment__skills-title">{{ __('lf.LF_teacher_judgment_skills') }}</h2>

                    @foreach ([['mapped', $mapped], ['others', $others]] as [$group, $items])
                        @if ($items->isNotEmpty())
                            <details class="teacher-judgment__group" @if ($group === 'mapped' || ($node && ! $node->mapped)) open @endif>
                                <summary class="teacher-judgment__group-title">
                                    {{ __('lf.LF_teacher_judgment_group_'.$group, ['count' => $items->count()]) }}
                                    <x-info-tip :text="__('lf.LF_teacher_judgment_group_'.$group.'_tip')" />
                                </summary>
                                <ul class="teacher-judgment__skill-list">
                                    @foreach ($items as $item)
                                        @php
                                            $count = (int) ($judgedCounts[$item->id] ?? 0);
                                        @endphp
                                        <li>
                                            <a class="teacher-judgment__skill {{ $node && $node->id === $item->id ? 'is-current' : '' }}"
                                               href="{{ route('teacher.cohorts.show', ['cohort' => $cohort->id, 'basis' => $basis->id, 'node' => $item->id]) }}"
                                               @if ($node && $node->id === $item->id) aria-current="true" @endif
                                               data-teacher-judgment-skill="{{ $item->id }}">
                                                <span class="teacher-judgment__skill-name">{{ $item->name_snapshot }}</span>
                                                @if ($item->labels)
                                                    <span class="teacher-judgment__skill-meta">{{ implode(' · ', $item->labels) }}</span>
                                                @endif
                                                <span class="teacher-judgment__skill-meta {{ $count > 0 ? 'is-done' : '' }}">
                                                    {{ $count > 0 ? __('lf.LF_teacher_judgment_judged_count', ['count' => $count, 'total' => $students->count()]) : __('lf.LF_teacher_judgment_not_judged') }}
                                                </span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    @endforeach
                </aside>

                <section class="admin-card teacher-judgment__students" aria-labelledby="teacher-judgment-node-title">
                    @if ($node)
                        <header class="teacher-judgment__node">
                            <h2 id="teacher-judgment-node-title" class="admin-form-section-title">{{ $node->name_snapshot }}</h2>
                            @if ($node->description_snapshot)
                                <p class="admin-form-section-help">{{ $node->description_snapshot }}</p>
                            @endif
                        </header>

                        @if ($students->isEmpty())
                            <p class="admin-form-empty-state">{{ __('lf.LF_teacher_judgment_no_students') }}</p>
                        @else
                            <div class="admin-table-wrap teacher-judgment__table-wrap">
                                <table class="table teacher-judgment__table">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ __('lf.LF_teacher_judgment_col_student') }}</th>
                                            <th scope="col">
                                                {{ __('lf.LF_teacher_judgment_col_level') }}
                                                <x-info-tip :text="__('lf.LF_teacher_judgment_col_level_tip')" />
                                            </th>
                                            <th scope="col"><span class="teacher-judgment__sr-only">{{ __('lf.table_actions') }}</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($students as $student)
                                            @php
                                                $last = $latest->get($student->id.':'.$node->id);
                                                $formId = 'teacher-judgment-form-'.$student->id;
                                                $isOld = (int) old('membership_id') === (int) $student->id && (int) old('node_id') === (int) $node->id;
                                            @endphp
                                            <tr data-teacher-judgment-student="{{ $student->id }}">
                                                <td data-label="{{ __('lf.LF_teacher_judgment_col_student') }}" class="teacher-judgment__student">{{ $student->name }}</td>
                                                <td data-label="{{ __('lf.LF_teacher_judgment_col_level') }}">
                                                    <span class="teacher-judgment__level" data-tone="{{ $tone($last->mastery_level_key ?? null) }}">
                                                        {{ $last ? $levelLabel($last->mastery_level_key) : __('lf.LF_teacher_judgment_not_judged') }}
                                                    </span>
                                                    @if ($last)
                                                        <span class="teacher-judgment__last">
                                                            {{ \Illuminate\Support\Carbon::parse($last->occurred_at)->format('d/m/Y H:i') }}@if ($last->mastery_score !== null) · {{ round((float) $last->mastery_score * 100) }}%@endif · {{ $last->teacher_name }}
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="teacher-judgment__action">
                                                    @if ($canJudge)
                                                        <button type="button" class="btn btn-secondary" aria-controls="{{ $formId }}"
                                                                aria-expanded="{{ $isOld ? 'true' : 'false' }}"
                                                                x-data
                                                                x-on:click="document.querySelectorAll('.teacher-judgment__form-panel').forEach((p) => { if (p.id !== '{{ $formId }}') p.hidden = true; }); const panel = document.getElementById('{{ $formId }}'); panel.hidden = ! panel.hidden; $el.setAttribute('aria-expanded', (! panel.hidden).toString()); if (! panel.hidden) panel.scrollIntoView({ block: 'start', behavior: 'smooth' })">
                                                            {{ $last ? __('lf.LF_teacher_judgment_rejudge') : __('lf.LF_teacher_judgment_judge') }}
                                                        </button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if ($canJudge)
                                {{-- One form per student, below the table so it can use the full width; one open at a time. --}}
                                @foreach ($students as $student)
                                    @php
                                        $formId = 'teacher-judgment-form-'.$student->id;
                                        $isOld = (int) old('membership_id') === (int) $student->id && (int) old('node_id') === (int) $node->id;
                                        // The service accepts an observation from the later of class start and the student's joining.
                                        $joinedLocal = $student->joined_at ? \Illuminate\Support\Carbon::parse($student->joined_at)->format('Y-m-d\TH:i') : null;
                                        $studentMinLocal = collect([$minLocal, $joinedLocal])->filter()->max();
                                    @endphp
                                    <div id="{{ $formId }}" class="teacher-judgment__form-panel" @unless ($isOld) hidden @endunless>
                                        <h3 class="teacher-judgment__form-title">{{ __('lf.LF_teacher_judgment_form_label', ['name' => $student->name]) }}</h3>
                                        @include('teacher-cohorts.partials.judgment-form')
                                    </div>
                                @endforeach
                            @endif
                        @endif
                    @endif
                </section>
            </div>
        @endif
    </div>
@endsection
