<button type="button" class="like {{ $liked ? 'on' : '' }}" wire:click="toggle" aria-pressed="{{ $liked ? 'true' : 'false' }}">♥ {{ $count }}</button>
