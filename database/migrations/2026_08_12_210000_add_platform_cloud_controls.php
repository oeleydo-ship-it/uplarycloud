<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{
public function up():void{Schema::table('provider_connections',fn(Blueprint $t)=>$t->dropForeign(['tenant_id']));Schema::table('provider_connections',function(Blueprint $t){$t->foreignId('tenant_id')->nullable()->change();$t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();$t->boolean('platform_managed')->default(false)->after('active')->index();});Schema::table('managed_server_plans',fn(Blueprint $t)=>$t->unsignedSmallInteger('markup_percentage')->default(0)->after('monthly_cost'));}
public function down():void{Schema::table('managed_server_plans',fn(Blueprint $t)=>$t->dropColumn('markup_percentage'));Schema::table('provider_connections',function(Blueprint $t){$t->dropColumn('platform_managed');$t->dropForeign(['tenant_id']);});}
};
