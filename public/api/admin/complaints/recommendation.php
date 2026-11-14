<?php
define('ICARS_API', true);
require_once dirname(__DIR__, 4) . '/includes/app_init.php';
api_guard(['POST'], [ROLE_ADMIN, ROLE_STAFF]);
$body=api_json_body();
$id=(int)($body['id']??0);
try {
    if ($id<=0) throw new ValidationException(['form'=>'Complaint id is required.']);
    if (!auth_has_role(ROLE_ADMIN)) {
        $assigned=complaint_find($id);
        if (!$assigned || !complaint_intelligence_can_review($assigned,auth_role(),auth_id())) abort(404, 'Complaint not found.');
    }
    $action=is_string($body['action']??null)?$body['action']:'';
    if ($action==='retry') {
        complaint_intelligence_retry($id);
        json_success([], 'Rule-based analysis completed.');
    }
    $values=[];
    foreach(['category_id','priority_id','severity_id','department_id'] as $key) $values[$key]=nullable_id($body[$key]??null);
    complaint_intelligence_decide($id,$action,$values,(int)auth_id());
} catch(ValidationException $ex) { json_error($ex->getMessage(),$ex->httpStatus,$ex->errors); }
flash_set('success','Recommendation decision recorded.');
json_success([], 'Recommendation decision recorded.');
