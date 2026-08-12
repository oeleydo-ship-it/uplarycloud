<?php
namespace App\Console\Commands;
use App\Jobs\IssueCertificateJob;use App\Models\Domain;use Illuminate\Console\Command;
class RenewDomainCertificates extends Command
{
    protected $signature='domains:renew-certificates';protected $description='Queue renewal checks for expiring managed certificates';
    public function handle():int{$domains=Domain::where('auto_renew',true)->where('ssl_enabled',true)->where(fn($query)=>$query->whereNull('certificate_expires_at')->orWhere('certificate_expires_at','<=',now()->addDays(config('networking.renew_before_days'))))->get();foreach($domains as $domain){$domain->update(['ssl_status'=>'pending']);IssueCertificateJob::dispatch($domain->id,$domain->tenant_id);}$this->info($domains->count().' certificate checks queued.');return self::SUCCESS;}
}
