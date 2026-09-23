{{-- The token in the path is the capability, and the action is root-relative for the same reason as the asset URLs. --}}
<form id="vault-form" method="POST" action="/vault/{{ $token }}" autocomplete="off">
    @csrf

    @if (!empty($error))
        <p class="notice error" role="alert">Not saved: {{ $error }}</p>
    @endif

    <div class="field">
        <label class="field-label" for="secret">{{ $label }}</label>
        <textarea id="secret" name="secret" placeholder="{{ $placeholder }}" autofocus required></textarea>
        <span class="hint">{{ $note }}</span>
        @if (!empty($checkTarget))
            <span class="hint">Before it is saved, the token is tried against {{ $checkTarget }}.</span>
        @endif
    </div>

    <button class="button" type="submit">{{ empty($checkTarget) ? 'Save secret' : 'Check and save' }}</button>
</form>
