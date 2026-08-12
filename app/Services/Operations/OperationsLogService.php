<?php
namespace App\Services\Operations;
use App\Models\OperationalLog;
class OperationsLogService{public function write(int $tenantId,string $category,string $severity,string $message,array $attributes=[]):OperationalLog{return OperationalLog::create(['tenant_id'=>$tenantId,'category'=>$category,'severity'=>$severity,'message'=>$message,'server_id'=>$attributes['server_id']??null,'application_deployment_id'=>$attributes['application_deployment_id']??null,'docker_container_id'=>$attributes['docker_container_id']??null,'backup_id'=>$attributes['backup_id']??null,'source'=>$attributes['source']??'control-plane','context'=>$attributes['context']??null,'occurred_at'=>now()]);}}
