@include('livewire.partials.manage-participants', [
    'getEntityVar' => fn () => 'campaign',
    'getEntityName' => fn () => 'Campaign',
    'getBackRoute' => fn () => route('campaigns.show', $campaign),
])
