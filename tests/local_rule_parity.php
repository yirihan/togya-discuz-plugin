<?php
// Compare rule bodies without loading either installation or touching a database.
$authority=getenv('TOGYA_RULE_AUTHORITY');
$candidate=getenv('TOGYA_PLUGIN_SOURCE')?:dirname(__DIR__).'/upload/source/plugin/pintogya';
if(!$authority||!is_dir($authority)||!is_dir($candidate))exit(2);
function ruleTokens($path,$name) {
    $tokens=token_get_all(file_get_contents($path));$found=null;
    for($i=0;$i<count($tokens);$i++) {
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;
        while(isset($tokens[$j])&&is_array($tokens[$j])&&in_array($tokens[$j][0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true))$j++;
        if(!isset($tokens[$j])||!is_array($tokens[$j])||$tokens[$j][0]!==T_STRING||$tokens[$j][1]!==$name)continue;
        if($found!==null)throw new RuntimeException('ambiguous_rule_symbol');
        $parts=array();$depth=0;$body=false;
        for(;$i<count($tokens);$i++) {
            $token=$tokens[$i];
            if(is_array($token)&&in_array($token[0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true))continue;
            $parts[]=is_array($token)?array($token[0],$token[1]):$token;
            if($token==='{'){$body=true;$depth++;}
            if(is_array($token)&&in_array($token[0],array(T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES),true))$depth++;
            if($token==='}'&&--$depth===0&&$body)break;
        }
        $found=$parts;
    }
    if($found===null)throw new RuntimeException('missing_rule_symbol');
    return $found;
}
$scope=array(
    'lib/native_task.php'=>array('pintogya_native_services'),
    'lib/activity_api.php'=>array('execute'),
    'lib/checkin_interactions.php'=>array('prepare','capabilities'),
    'lib/activity_instant_config.php'=>array('operationChanges'),
    'lib/activity_instant_settlement.php'=>array('grant'),
    'lib/activity_settlement.php'=>array('settle','settleTimeout','assertSettlementEnabled'),
    'lib/activity_publish.php'=>array('issue','checkedReward'),
    'lib/activity_official_publish.php'=>array('publish'),
);
$cases=array();$passed=true;
try {
    foreach($scope as$file=>$names)foreach($names as$name) {
        $same=ruleTokens($authority.'/'.$file,$name)===ruleTokens($candidate.'/'.$file,$name);
        $cases[]=array('file'=>$file,'symbol'=>$name,'matched'=>$same);$passed=$passed&&$same;
    }
}catch(Throwable $error){$passed=false;$cases[]=array('error'=>'rule_comparison_failed');}
echo json_encode(array('passed'=>$passed,'cases'=>$cases,'scope'=>'Exact PHP tokens for twelve restored rule methods; ignores comments/whitespace only. Not complete plugin parity, authorization execution or payout proof.')).PHP_EOL;
exit($passed?0:1);
