@include('livewire.partials.manage-participants', [
    'getEntityVar' => fn () => 'game',
    'getEntityName' => fn () => 'Game',
    'getBackRoute' => fn () => route('games.show', $game),
])
