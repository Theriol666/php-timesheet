<?php
/** 
Get CLI/WEB args
@var array $args
**/
$args = getArgs();
/**
Get month to show (current as default)
@var string $period
**/
$period = $args['period']??date('m', time());
@define("PERIOD", $period);
/**
Set the regex rule to find start value for daily activities
@const string PERIOD_REGEX
**/
const PERIOD_REGEX = "/\[%s\/(.\d) (.\d):(.\d)\]/";
/**
Map time values
@const array TIME_VAR
**/
const TIME_VAR = [
	"h" => "1",
	"m" => "60",
];

/**
Set thee regex rule to find all tasks
@const string TASK_PATTERN
**/
CONST TASK_PATTERN = "/(#(?:[^\S]|\w)+)\:/";
/**
Set task separator simble
@const string TASK_SEPARATOR
**/
CONST TASK_SEPARATOR = "|";

$tasks = [];

/**
Get file rows for elaboration
@var array $source
**/
$source = file($args['file']?? __DIR__ . '/timesheet.txt');

if($source){
	
	// Parsing main variables
	$projectTime = "";
	$currentDay = 0;
	$lastDay = 0;
	$startProjectString = getPeriodRegex(PERIOD);
	$projectsTotalTime = [];
	$dayTotalTime = 0;
	$today = date("m/d");
	// Rows cycle
	foreach($source as $lineIndex => $line){

		// skip not usable rows
		if(isToSkip($line)){ continue; }
		// check if the current row contains a starting daily activities information
		if(isStartTime($line, $lineIndex)){

			// show current total day of today
			if($dayTotalTime > 0 && ($lastDay === 0 || PERIOD."/".$lastDay == $today)){
				showDailyTotal();
			}
		
			writeString("------------------------------------------");
			writeString(PERIOD."/".$currentDay.":\n");
			writeProjectTime("");
			continue;
		
		}else{
			// break the script if the current row refers to a previous month thant current
			if(breakByPeriod($line)){
				// show current total day of today if it's the first report of the month
				if($dayTotalTime > 0 && ($lastDay == 0 || PERIOD."/".$lastDay == $today)){
					showDailyTotal();
				}
				
				break;
			}
		}
		
		// check if the parser is elaborating a daily activities
		if(isStartTraking()){
			// get and write information for current project
			writeProjectTime($line);
			continue;
		}
	}
	
	if(!empty($projectsTotalTime)){
		writeString("***************************");
		writeString("Total time worked:");
		foreach($projectsTotalTime as $project => $time){
			writeString("- ".$project.": ". $time."h");
			if(!isset($tasks[$project])){
				continue;
			}
			
			$totalTasks = 0;
			foreach($tasks[$project] as $task => $taskTime){
				writeString("  - ".$task.": ". $taskTime."h");
				$totalTasks += $taskTime;
			}
			
			$other = $time - $totalTasks;
			if($other > 0){
				writeString("  - Other: ". $other."h");
			}
		}
	}
}

/**
Skip the row if it's empty or equals to filtered values
@param string $line
@return bool
**/
function isToSkip($line){
	
	$toSkip = [
		"##################################################",
		""
	];
	$line = trim($line);
	return empty($line) || in_array($line,$toSkip);
}

function isStartTime($line, $lineIndex){
	global $currentDay, $lastDay, $startProjectString;

	if(preg_match($startProjectString, $line)){
		preg_match($startProjectString, $line, $match);
		if($currentDay !== $match[1]){
			$lastDay = $currentDay;
		}
		$currentDay = $match[1];
		
		return true;
	}
	
	return false;
}

/**
Verify if the script is elaborating the same day
@global string $currentDay
@global string $lastDay
@return bool
**/
function isStartTraking(){
	global $currentDay, $lastDay;
	
	if($currentDay !== $lastDay){ return true; }
	else { return false; }
}


/**
Saves the time values for each task to sum them, and clean the ouput string from time tracked
@param string $line
@return strng
**/
function writeProjectTime($line){
	$totalTime = [];
	
	$lineExploded = explode(": ", $line);
	$project = array_shift($lineExploded);
	unset($lineExploded);
	
	$filteredLines = str_replace([$project, "@smartworking:", "@office:"],"",$line);
	$tasks = array_filter(explode(TASK_SEPARATOR, $filteredLines));
	
	foreach($tasks as $taskLine){
		
		// find task
		preg_match_all(TASK_PATTERN, $taskLine, $matchedTasks);
		$hasTask = !empty($matchedTasks[1]);
		
		foreach(array_keys(TIME_VAR) as $time){
			$regex = "/.[[:digit:]]".$time."/";
			preg_match_all($regex, $taskLine, $match);
			if(!empty(array_filter($match))){
				
				if($hasTask){
					$match[0] = [ $matchedTasks[1][0]  => $match[0][0] ];
				}
				$totalTime[] = $match[0];
			}
			$line = trim($line);
			$line = preg_replace($regex, "", $line);
			$line = str_replace(":  |", " |", $line);
			$line = str_replace(": |", " |", $line);
			$line = str_replace(":|", " |", $line);
			if (substr($line, -1, 1) === ":") {
				$line = substr($line, 0, -1);
			}
		}
	}
	
	$line .= "\n";

	writeString($line, false);

	calcTotalTime($totalTime,$line,$project);
}

/**
Caulcate project's time traked and print it
@param array $totalTime
@param string $line
@param string $project
@return void
**/
function calcTotalTime($totalTime,$line,$project){
	global $projectsTotalTime, $tasks, $dayTotalTime;
	
	if(empty($totalTime)){
		return $line;
	}

	
	$timeSpent = 0;
	
	foreach($totalTime as $times){

		foreach($times as $index => $time){
			
			$taskTime = 0;
			foreach(TIME_VAR as $timeUnit => $timeValue){
				if(strpos($time, $timeUnit) !== false){
					$time = (int) str_replace($timeUnit,"",$time);
					if($timeUnit == "h"){
						$taskTime += $time * $timeValue;
					}elseif($timeUnit == "m"){
						$taskTime += $time / $timeValue;
					}
				}
			}
			if(strpos($index,"#") !== false){
				if(!isset($tasks[$project][$index])){
					$tasks[$project][$index] = 0;
				}
				$tasks[$project][$index] += $taskTime;
			}
			$timeSpent += $taskTime;
		}
	}
	
	if(!isset($projectsTotalTime[$project])){
		$projectsTotalTime[$project] = 0;
	}
	$projectsTotalTime[$project] += $timeSpent;
	$dayTotalTime += $timeSpent;
	writeString("> Total: " .$timeSpent. "h\n");
}

/**
Verify if the script is parsing a new month lower than period selected
@param string $line
@return bool
**/
function breakByPeriod($line){
	
	$period = PERIOD;
	
	$prevPeriod = getPeriodRegex(str_pad($period - 1, 2, '0', STR_PAD_LEFT));
	
	if(preg_match($prevPeriod, $line)){
		return true;
	}else{
		return false;
	}
}

/**
Print in CLI the ouput of the script and check if use \n or not
@param string $string
@param bool $useN
@return void
**/
function writeString($string, $useN = true){
	echo $useN ? $string."\n" : $string;
}

/**
Return a regex string used to check date information
@param string $period
@return string
**/
function getPeriodRegex($period){
	return sprintf(PERIOD_REGEX, $period);
}

/**
Show daily total time
@return void
**/
function showDailyTotal(){
	global $dayTotalTime;
	
	writeString("");
	writeString("Daily total: ". $dayTotalTime. "h");
	writeString("");
	$dayTotalTime = 0;
}

/**
Verify if args are passed on command execution
@return array
**/
function getArgs(){
	$validArgs = [
		'period',
		'file'
	];
		
	$runner = isset($_SERVER['argv']) && $_SERVER['argv'] > 1 ? $_SERVER['argv'] : $_GET;

	$args = [];

	foreach($runner as $argIndex => $arg){
		
		if($argIndex === 0){
			continue;
		}
		
		try{
			list($argKey, $argValue) = explode("=" , $arg);
			if(in_array($argKey, $validArgs)){
				$args[$argKey] = $argValue;
			}
			
		}catch(Exception $e){
			unset($e);
			writeString("Arg not valid: ".$arg);
		}
	}
	
	return $args;
}