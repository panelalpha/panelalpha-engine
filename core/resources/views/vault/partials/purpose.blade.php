{{--
    Why this link exists, in the words of whoever asked for it.

    Shown above the field rather than below it: somebody handed a link and
    asked for a credential should be able to see what it is wanted for before
    they go and create one, not after they have pasted it. Optional, so the
    block disappears entirely when no purpose was given.
--}}
@if (!empty($purpose))
    <p class="purpose"><span class="purpose-label">What this is for</span>{{ $purpose }}</p>
@endif
