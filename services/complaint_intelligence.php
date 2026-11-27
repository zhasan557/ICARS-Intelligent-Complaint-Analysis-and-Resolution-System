<?php
declare(strict_types=1);

/** Explainable local rules for complaint triage. No model or external service is used. */
function ci_normalize(string $text): string
{
    return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '', 'UTF-8');
}

function ci_score_text(string $text, array $terms): int
{
    $text = ' ' . ci_normalize($text) . ' ';
    $score = 0;
    foreach ($terms as $term => $weight) {
        if (str_contains($text, ' ' . ci_normalize((string)$term) . ' ')) $score += (int)$weight;
    }
    return $score;
}

/** Pure rule engine; lookups are supplied from the current database values. */
function complaint_intelligence_rules(array $complaint, array $categories, array $departments, array $staff, array $resolved): array
{
    $text = implode(' ', [(string)($complaint['title'] ?? ''), (string)($complaint['description'] ?? ''), (string)($complaint['location'] ?? '')]);
    $maps = [
        'IT & Network' => ['network','wifi','wi fi','internet','router','computer','software','email','password','login','server'],
        'Facilities & Maintenance' => ['electricity','electric','power','water','plumbing','cleaning','cleanliness','broken','repair','classroom','equipment','air conditioner','ac'],
        'Academic' => ['academic','course','exam','result','grade','teacher','faculty','class schedule','lecture'],
        'Security & Safety' => ['security','unsafe','theft','stolen','harassment','assault','fire','injury','danger','threat'],
        'Student Services' => ['transport','bus','shuttle','hostel','welfare','student service'],
        'Administrative' => ['certificate','admission','document','registration','office','administrative','record'],
        'Finance & Fees' => ['fee','payment','refund','scholarship','tuition','finance'],
        'Library' => ['library','book','borrowing','librarian'],
    ];
    $best = null; $bestScore = 0;
    foreach ($categories as $cat) {
        $name = (string)$cat['name']; $score = 0;
        foreach ($maps as $hint => $terms) {
            if (ci_score_text($text, $terms) > 0 && ci_score_text($name, [$hint => 1]) > 0) $score += ci_score_text($text, $terms);
        }
        $score += ci_score_text($text, array_fill_keys(array_filter(preg_split('/\W+/u', ci_normalize($name)) ?: []), 1));
        if (($complaint['category_id'] ?? null) !== null && (int)$cat['id'] === (int)$complaint['category_id']) $score += 2;
        if ($score > $bestScore) { $bestScore = $score; $best = $cat; }
    }
    $chosen = $best ?: null;
    $categoryConfidence = min(0.95, $bestScore > 0 ? 0.45 + min(0.45, $bestScore * 0.06) : 0.25);
    $existing = array_values(array_filter($categories, static fn($c) => (int)$c['id'] === (int)($complaint['category_id'] ?? 0)))[0] ?? null;
    if ($existing && (!$chosen || $bestScore <= 2)) { $chosen = $existing; $categoryConfidence = 0.55; }

    $urgent = ci_score_text($text, ['urgent'=>3,'immediately'=>3,'emergency'=>5,'outage'=>3,'deadline'=>2,'আজ'=>3]);
    $safety = ci_score_text($text, ['fire'=>5,'injury'=>5,'injured'=>5,'danger'=>5,'unsafe'=>4,'electric shock'=>5,'harassment'=>5,'assault'=>5,'threat'=>5]);
    preg_match('/\b(\d{1,6})\s*(?:users|students|people|staff|persons|জন)\b/ui', $text, $m);
    $affected = isset($m[1]) ? (int)$m[1] : null;
    $impact = $affected === null ? 0 : ($affected >= 100 ? 5 : ($affected >= 25 ? 4 : ($affected >= 10 ? 3 : ($affected >= 3 ? 2 : 1))));
    $priority = ($safety >= 5 || $urgent >= 5 || $impact >= 5) ? 'Critical' : (($urgent >= 3 || $safety >= 4 || $impact >= 4) ? 'High' : (($impact >= 2 || $bestScore >= 4) ? 'Medium' : 'Low'));
    $severity = $safety >= 5 ? 'Critical' : ($safety >= 4 || $impact >= 4 ? 'High' : ($impact >= 2 ? 'Medium' : 'Low'));
    $rationale = ['Category matched ' . ($bestScore ? $bestScore . ' keyword/category points' : 'no distinctive terms') . '.'];
    if ($affected === null) $rationale[] = 'Affected-user count was not provided; impact is not inferred and priority/severity may be understated.';
    else $rationale[] = 'Detected approximately ' . $affected . ' affected users (impact score ' . $impact . ').';
    if ($safety) $rationale[] = 'Safety terms contributed score ' . $safety . '.';
    if ($urgent) $rationale[] = 'Urgency terms contributed score ' . $urgent . '.';
    $rationale[] = 'Priority is ' . $priority . ' and severity is ' . $severity . ' under the local safety, urgency and impact thresholds.';

    $dept = null;
    $deptHints = [
        'IT & Network'=>'IT Support', 'Facilities & Maintenance'=>'Facilities & Maintenance', 'Academic'=>'Academic Affairs',
        'Security & Safety'=>'Security', 'Student Services'=>'Student Affairs', 'Finance & Fees'=>'Finance & Accounts',
        'Library'=>'Library', 'Administrative'=>'General Administration'
    ];
    if ($chosen) foreach ($departments as $d) {
        foreach ($deptHints as $cat => $hint) if (ci_score_text((string)$chosen['name'], [$cat=>1]) && ci_score_text((string)$d['name'], [$hint=>1])) { $dept=$d; break 2; }
    }
    if (!$dept && $departments) $dept = array_values(array_filter($departments, static fn($d) => ci_score_text((string)$d['name'], ['General Administration'=>1])))[0] ?? null;
    $candidates = array_values(array_filter($staff, static fn($s) => $dept && (int)$s['department_id'] === (int)$dept['id']));
    usort($candidates, static function($a,$b) use($text) { $as=ci_score_text($text, array_fill_keys(preg_split('/[,; ]+/u',(string)($a['expertise']??''))?:[],1)); $bs=ci_score_text($text,array_fill_keys(preg_split('/[,; ]+/u',(string)($b['expertise']??''))?:[],1)); return ($bs <=> $as) ?: ((int)$a['open_load'] <=> (int)$b['open_load']); });
    $recommendedStaff = $candidates[0] ?? null;
    $steps=[]; $similar=[];
    foreach ($resolved as $case) {
        $same = $chosen && (int)$case['category_id'] === (int)$chosen['id'];
        $overlap = ci_score_text(
            $text, 
            array_fill_keys(
                array_filter(
                    preg_split('/\W+/u', ci_normalize((string)$case['description'])) ?: []
                    ),
                    1
                ))
            ;
        $sc = ($same ? 5 : 0) + min(5,$overlap);
        if ($sc > 0) $similar[]=['case'=>$case,'score'=>$sc];
    }
    usort($similar, static fn($a,$b)=>$b['score']<=>$a['score']);

    foreach (array_slice($similar,0,3) as $item) 
        if (trim((string)$item['case']['resolution']) !== '') 
            $steps[]=(string)$item['case']['resolution'];
    
    $hasHistory = count($steps)>0;
    if (!$hasHistory) $steps = ['Review the reported issue and verify it at the stated location.', 'Route the case to the responsible department for diagnosis.', 'Record the corrective action and confirm the outcome with the complainant.'];
    return ['category_id'=>$chosen ? (int)$chosen['id'] : null, 'category_name'=>$chosen['name'] ?? null, 'priority'=>$priority,'severity'=>$severity,
        'department_id'=>$dept ? (int)$dept['id'] : null,'department_name'=>$dept['name'] ?? null,
        'staff_id'=>$recommendedStaff ? (int)$recommendedStaff['id'] : null,'staff_name'=>$recommendedStaff['full_name'] ?? null,
        'staff_reason'=>$recommendedStaff ? 'Expertise and current open workload were used to rank available staff; this is a recommendation only.' : 'No available staff member was found in the recommended department.',
        'resolution'=>$steps,'historical_cases'=>$hasHistory,'confidence'=>round(($categoryConfidence + ($hasHistory ? .75 : .4))/2,2), 'rationale'=>$rationale];
}

function complaint_intelligence_context(): array
{
    $staff=db_all("SELECT u.id,u.full_name,sp.department_id,sp.expertise, (SELECT COUNT(*) FROM complaints c JOIN complaint_statuses s ON s.id=c.status_id WHERE c.assigned_to=u.id AND s.name IN ('Assigned','In Progress','Reopened')) open_load FROM users u JOIN roles r ON r.id=u.role_id AND r.name='staff' JOIN staff_profiles sp ON sp.user_id=u.id WHERE u.is_active=1 AND sp.is_available=1");
    $resolved=db_all("SELECT category_id,description,resolution_details AS resolution FROM complaint_resolution_knowledge ORDER BY resolved_at DESC LIMIT 100");
    return [complaint_categories(), departments_all(), $staff, $resolved];
}

/** Isolate failures so callers can keep the primary workflow successful. */
function complaint_intelligence_attempt(int $complaintId, callable $analyser): bool
{
    try { $analyser(); return true; }
    catch (Throwable $error) {
        try { complaint_history_add($complaintId,'recommendation_failed',null,null,null,'internal','Rule analysis failed; complaint retained for manual triage'); }
        catch (Throwable) { /* history storage may be part of the same failure; log remains the fallback */ }
        log_message('error', 'Complaint rule analysis failed', ['complaint_id'=>$complaintId,'error'=>$error->getMessage()]);
        return false;
    }
}

function complaint_intelligence_store(int $id, array $suggestion): void
{
    db_exec(
        'INSERT INTO complaint_recommendations 
        (complaint_id,suggestion_json,status) 
        VALUES (?, ?, \'pending\') 
        ON DUPLICATE KEY UPDATE 
        suggestion_json=VALUES(suggestion_json), 
        status=\'pending\', 
        decision_json=NULL, 
        decided_by=NULL, 
        decided_at=NULL', 
        [
            $id,json_encode($suggestion,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
        ]
    );
    complaint_history_add(
        $id,
        'recommendation_generated',
        null,
        null,
        null,
        'internal',
        'Rule-based triage recommendation generated',
        null,
        ['confidence'=>$suggestion['confidence']]
    );
}

/** Retry a previously failed/missing analysis without changing the complaint or assigning staff. */
function complaint_intelligence_retry(int $id): void
{
    if (complaint_intelligence_get($id)) 
        throw new ValidationException([
            'form'=>'A recommendation already exists for this complaint.'
        ]);
    $c=complaint_find($id);

    if (!$c) 
        throw new ValidationException(
            ['form'=>'Complaint not found.'],
            'Complaint not found.',
            404
        );
    [$categories,$departments,$staff,$resolved]=complaint_intelligence_context();
    $suggestion=complaint_intelligence_rules(
        $c,
        $categories,
        $departments,
        $staff,
        $resolved
    );
    complaint_intelligence_store($id,$suggestion);
}

function complaint_intelligence_get(int $id): ?array
{
    $row=db_one(
        'SELECT suggestion_json,status,decision_json,decided_by,decided_at 
        FROM complaint_recommendations 
        WHERE complaint_id=?',
        [$id]
    );
    if (!$row) return null;

    $row['suggestion']=
        json_decode($row['suggestion_json'],true) ?: [];
    $row['decision']=
        json_decode((string)$row['decision_json'],true) ?: null;
    return $row;
}

/** Admins may review any recommendation; staff may review only their own assigned cases. */
function complaint_intelligence_can_review(array $complaint, ?string $role, ?int $actorId): bool
{
    return $role === ROLE_ADMIN || 
    (
        $role === ROLE_STAFF && 
        $actorId !== null && 
        (int)($complaint['assigned_to'] ?? 0) === $actorId
    );
}

function complaint_intelligence_decide(int $id,string $action,array $input,int $actor): void
{
    if (!in_array($action,['accept','modify','reject'],true)) 
        throw new ValidationException([
            'action'=>'Choose accept, modify or reject.'
        ]);

    $rec=complaint_intelligence_get($id);

    if (!$rec) 
        throw new ValidationException(
            ['form'=>'No recommendation exists for this complaint.'], 'Recommendation not found.',
            404
        );

    $decision=['action'=>$action];
    
    if ($action!=='reject') {
        $suggestion=$rec['suggestion'];
        $values=$action==='accept' ? [
            'category_id'=>$suggestion['category_id'],'priority_id'=>db_value(
                'SELECT id FROM complaint_levels WHERE name=?',[$suggestion['priority']]
            ),
            'severity_id'=>db_value(
                'SELECT id FROM complaint_levels WHERE name=?',[$suggestion['severity']]
            ),
            'department_id'=>$suggestion['department_id']
        ] 
        : $input;
        complaint_classify($id,$values,'Recommendation '.$action,$actor);
        $decision['values']=$values;
    }
    db_transaction(function() use($id,$action,$decision,$actor):void {
        $c=complaint_lock($id);
        db_exec(
            'UPDATE complaint_recommendations 
            SET status=?,decision_json=?,decided_by=?,decided_at=NOW() WHERE complaint_id=?',
            [
                $action,
                json_encode($decision,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                $actor,
                $id
            ]
        );
        complaint_history_add(
            $id,
            'recommendation_'.$action,
            (int)$c['status_id'],
            (int)$c['status_id'],
            $actor,'internal',
            'Recommendation '.$action,
            null,
            $decision
        );
        audit_log(
            'complaint_recommendation_'.$action,
            'complaint',
            $id,
            null,
            $decision,
            $actor
        );
    });
}
