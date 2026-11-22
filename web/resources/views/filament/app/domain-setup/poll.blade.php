{{-- Re-checks the DMARC record every 30 seconds while the customer waits for DNS to update. --}}
<div wire:poll.30s="pollVerification"></div>
