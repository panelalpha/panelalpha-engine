@php
    $providers = [
        'github' => [
            'label' => 'GitHub',
            'icon' => 'icons/github.svg',
            'placeholder' => 'github_pat_…  or  ghp_…',
            'field' => 'Paste the token here',
        ],
        'gitlab' => [
            'label' => 'GitLab',
            'icon' => 'icons/gitlab.svg',
            'placeholder' => 'glpat-…',
            'field' => 'Paste the token here',
        ],
        'bitbucket' => [
            'label' => 'Bitbucket',
            'icon' => 'icons/bitbucket.svg',
            'placeholder' => 'ATBB…',
            'field' => 'Paste the app password here',
        ],
        'other' => [
            'label' => 'Other (any Git server)',
            'icon' => 'icons/git-white.svg',
            'placeholder' => 'token  or  username:token',
            'field' => 'Paste the token here',
        ],
    ];

    // The repository being checked against names its forge; without one, GitHub.
    $host = empty($checkTarget) ? null : strtolower(strtok($checkTarget, '/'));
    $selected = match (true) {
        $host === null, $host === 'github.com' => 'github',
        $host === 'gitlab.com' => 'gitlab',
        $host === 'bitbucket.org' => 'bitbucket',
        default => 'other',
    };
@endphp

<div class="field">
    <label class="field-label" for="provider">Where is your repository?</label>
    <div class="select-wrap">
        <span class="provider-icon"><span id="provider-icon" style="background-image:url('/vault/{{ $providers[$selected]['icon'] }}')"></span></span>
        <select id="provider" name="provider" autocomplete="off">
            @foreach ($providers as $value => $provider)
                <option value="{{ $value }}" data-field="{{ $provider['field'] }}" data-placeholder="{{ $provider['placeholder'] }}" data-icon="/vault/{{ $provider['icon'] }}" @selected($value === $selected)>{{ $provider['label'] }}</option>
            @endforeach
        </select>
        <span class="chevron" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
        </span>
    </div>
</div>

@foreach ($providers as $value => $provider)
    <div data-provider-steps="{{ $value }}" @if ($value !== $selected) hidden @endif>
        @include('vault.steps.' . ($value === 'github' || $value === 'gitlab' || $value === 'bitbucket' ? $value : 'other'))
    </div>
@endforeach

@include('vault.partials.paste', [
    'label' => $providers[$selected]['field'],
    'placeholder' => $providers[$selected]['placeholder'],
    'note' => $note,
])


@push('scripts')
    <script src="/vault/vault.js" defer></script>
@endpush
