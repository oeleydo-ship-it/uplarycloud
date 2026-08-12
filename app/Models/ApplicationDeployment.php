<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ApplicationDeployment extends Model
{
    use SoftDeletes;
    protected $fillable = ['tenant_id','application_id','build_pack_id','server_id','created_by','rolled_back_from_id','uuid','name','slug','deployment_type','framework','description','docker_image','docker_tag','container_port','domain','cpu_limit','memory_limit_mb','disk_limit_gb','auto_start','backup_enabled','restart_policy','git_provider','repository_url','branch','commit_hash','deploy_key','runtime_version','root_directory','package_manager','install_command','build_command','start_command','output_directory','database_engine','enable_redis','enable_queue','enable_scheduler','enable_reverb','auto_deploy','webhook_secret','build_status','last_webhook_at','status','progress','current_stage','last_error','started_at','completed_at','deployed_at'];
    protected static function booted(): void { static::creating(function (self $model): void { $model->uuid ??= (string) Str::uuid(); $model->slug ??= Str::slug($model->name).'-'.Str::lower(Str::random(5)); }); }
    protected function casts(): array { return ['status'=>DeploymentStatus::class,'auto_start'=>'boolean','backup_enabled'=>'boolean','enable_redis'=>'boolean','enable_queue'=>'boolean','enable_scheduler'=>'boolean','enable_reverb'=>'boolean','auto_deploy'=>'boolean','deploy_key'=>'encrypted','webhook_secret'=>'encrypted','started_at'=>'datetime','completed_at'=>'datetime','deployed_at'=>'datetime','last_webhook_at'=>'datetime','cpu_limit'=>'decimal:2']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function buildPack(): BelongsTo { return $this->belongsTo(BuildPack::class); }
    public function server(): BelongsTo { return $this->belongsTo(Server::class)->withTrashed(); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function environmentVariables(): HasMany { return $this->hasMany(DeploymentEnvironmentVariable::class); }
    public function steps(): HasMany { return $this->hasMany(DeploymentStep::class)->orderBy('position'); }
    public function logs(): HasMany { return $this->hasMany(DeploymentLog::class)->orderBy('occurred_at'); }
    public function releases(): HasMany { return $this->hasMany(DeploymentRelease::class)->latest('deployed_at'); }
    public function domains(): HasMany { return $this->hasMany(Domain::class); }
    public function containers(): HasMany { return $this->hasMany(DockerContainer::class, 'application_deployment_id'); }
}
