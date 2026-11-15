<?php
declare(strict_types=1);
define('ICARS_ROOT', dirname(__DIR__, 2));
require ICARS_ROOT . '/includes/constants.php';
require ICARS_ROOT . '/includes/errors.php';
require ICARS_ROOT . '/includes/validation.php';
require ICARS_ROOT . '/services/complaint_intelligence.php';

$ok=0; $failed=0;
function test_rule(string $name, callable $fn): void { global $ok,$failed; try {$fn();$ok++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: {$e->getMessage()}\n";} }
function expect(bool $value,string $message):void { if(!$value) throw new RuntimeException($message); }
$cats=[['id'=>11,'name'=>'IT & Network'],['id'=>12,'name'=>'Facilities & Maintenance'],['id'=>13,'name'=>'Academic'],['id'=>14,'name'=>'Security & Safety'],['id'=>15,'name'=>'Student Services'],['id'=>16,'name'=>'Administrative'],['id'=>17,'name'=>'Finance & Fees'],['id'=>18,'name'=>'Library'],['id'=>19,'name'=>'Other']];
$deps=[['id'=>21,'name'=>'IT Support'],['id'=>22,'name'=>'Facilities & Maintenance'],['id'=>23,'name'=>'Academic Affairs'],['id'=>24,'name'=>'Security'],['id'=>25,'name'=>'Student Affairs'],['id'=>26,'name'=>'General Administration'],['id'=>27,'name'=>'Finance & Accounts'],['id'=>28,'name'=>'Library']];
$staff=[['id'=>31,'full_name'=>'Network tech','department_id'=>21,'expertise'=>'network wifi router','open_load'=>3],['id'=>32,'full_name'=>'General tech','department_id'=>21,'expertise'=>'hardware','open_load'=>1]];
$base=['title'=>'Wi-Fi network outage','description'=>'Wi-Fi is down in lab; 40 students cannot connect.','location'=>'Lab 4'];
test_rule('maps proposal topic to existing DB category and responsible department',function()use($base,$cats,$deps,$staff){$r=complaint_intelligence_rules($base,$cats,$deps,$staff,[]);expect($r['category_id']===11,'wrong category');expect($r['department_id']===21,'wrong department');});
test_rule('critical safety and affected-user signal increase priority/severity',function()use($cats,$deps){$r=complaint_intelligence_rules(['title'=>'Emergency fire hazard','description'=>'Fire and unsafe electrical wiring affecting 120 students','location'=>'Hall'],$cats,$deps,[],[]);expect($r['priority']==='Critical','priority not critical');expect($r['severity']==='Critical','severity not critical');});
test_rule('missing affected count is not guessed and limitation is disclosed',function()use($cats,$deps,$base){$r=complaint_intelligence_rules(['title'=>'Broken desk','description'=>'A desk is broken in classroom','location'=>'Room 2'],$cats,$deps,[],[]);expect($r['priority']!=='Critical','invented critical priority');expect(str_contains(implode(' ',$r['rationale']),'not provided'),'missing-count limitation absent');});
test_rule('staff recommendation uses expertise and workload but no assignment field',function()use($base,$cats,$deps,$staff){$r=complaint_intelligence_rules($base,$cats,$deps,$staff,[]);expect($r['staff_id']===31,'expert match should lead');expect(!array_key_exists('assigned_to',$r),'recommendation auto assigns');});
test_rule('no matching staff is explicit',function()use($base,$cats,$deps){$r=complaint_intelligence_rules($base,$cats,$deps,[],[]);expect($r['staff_id']===null,'staff should be absent');expect(str_contains($r['staff_reason'],'No available'),'missing-staff rationale absent');});
test_rule('no resolved cases produces generic recommendation and disclosure',function()use($base,$cats,$deps){$r=complaint_intelligence_rules($base,$cats,$deps,[],[]);expect($r['historical_cases']===false,'history incorrectly matched');expect(count($r['resolution'])===3,'generic steps missing');});
test_rule('resolved case resolution is preferred when category and text match',function()use($base,$cats,$deps){$cases=[['category_id'=>11,'description'=>'Wi-Fi network outage lab students cannot connect','resolution'=>'Replaced faulty access point.']];$r=complaint_intelligence_rules($base,$cats,$deps,[],$cases);expect($r['historical_cases']===true,'case not matched');expect($r['resolution'][0]==='Replaced faulty access point.','resolution not reused');});
test_rule('invalid review action is rejected',function(){try{complaint_intelligence_decide(1,'execute',[],1);}catch(ValidationException $e){return;}throw new RuntimeException('invalid action accepted');});
test_rule('review authorization allows admins and only the assigned staff member',function(){expect(complaint_intelligence_can_review(['assigned_to'=>7],ROLE_ADMIN,2),'admin denied');expect(complaint_intelligence_can_review(['assigned_to'=>7],ROLE_STAFF,7),'assigned staff denied');expect(!complaint_intelligence_can_review(['assigned_to'=>7],ROLE_STAFF,8),'unassigned staff allowed');expect(!complaint_intelligence_can_review(['assigned_to'=>7],ROLE_USER,7),'complainant allowed');});
test_rule('analysis failure is contained and reports fallback status',function(){$ok=complaint_intelligence_attempt(9,static function(){throw new RuntimeException('simulated');});expect($ok===false,'analysis failure escaped or reported success');});
echo "\n$ok passed, $failed failed\n"; exit($failed?1:0);
