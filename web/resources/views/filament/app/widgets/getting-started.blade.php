<x-filament-widgets::widget>
    @if (! $hasDomains)
        <x-filament::empty-state
            icon="heroicon-o-globe-alt"
            heading="Add your first domain"
            :description="$canCreate
                ? 'Add a domain and we\'ll walk you through publishing a DMARC record. Reports usually start arriving within a day, and this dashboard fills up as they do.'
                : 'No domains have been added yet. Ask an owner or admin of this workspace to add one.'"
        >
            @if ($canCreate)
                <x-slot name="footer">
                    <x-filament::button tag="a" :href="$createUrl" icon="heroicon-m-plus">
                        Add a domain
                    </x-filament::button>
                </x-slot>
            @endif
        </x-filament::empty-state>
    @else
        <x-filament::empty-state
            icon="heroicon-o-clock"
            heading="Waiting for the first reports"
            :description="$unfinished
                ? 'The DMARC record for ' . $unfinished->name . ' isn\'t live yet. Finish setting it up so mail providers know where to send reports.'
                : 'Your DMARC records are live. Mail providers send reports about once a day, so the first ones usually arrive within 24 to 48 hours.'"
        >
            @if ($setupUrl)
                <x-slot name="footer">
                    <x-filament::button tag="a" :href="$setupUrl" icon="heroicon-m-arrow-right">
                        Continue setup
                    </x-filament::button>
                </x-slot>
            @endif
        </x-filament::empty-state>
    @endif
</x-filament-widgets::widget>
