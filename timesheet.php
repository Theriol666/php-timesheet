<?php

declare(strict_types=1);

/**
 * Timesheet Parser Script
 * 
 * Usage: php timesheet.php [period=mm] [file=path/to/file]
 */

class Timesheet
{
    private const PERIOD_REGEX = "/\[%s\/(.\d) (.\d):(.\d)\]/";
    private const TASK_PATTERN = "/(#(?:[^\S]|\w)+)\:/";
    private const TASK_SEPARATOR = "|";
    private const TIME_VAR = [
        "h" => 1.0,
        "m" => 60.0,
    ];

    private string $period;
    private string $filePath;
    private array $tasks = [];
    private array $projectsTotalTime = [];
    private float $dayTotalTime = 0;
    private string $currentDay = "";
    private string $lastDay = "";
    private string $startProjectString;

    /**
     * Initialize the timesheet parser.
     * 
     * @param array $args The command line arguments.
     */
    public function __construct(array $args)
    {
        $this->period = $args['period'] ?? date('m');
        $this->filePath = $args['file'] ?? __DIR__ . '/timesheet.txt';
        $this->startProjectString = sprintf(self::PERIOD_REGEX, $this->period);
    }

    /**
     * Run the timesheet parser.
     */
    public function run(): void
    {
        if (!file_exists($this->filePath)) {
            $this->writeString("Error: File not found: " . $this->filePath);
            return;
        }

        $handle = fopen($this->filePath, "r");
        if (!$handle) {
            $this->writeString("Error: Unable to open file: " . $this->filePath);
            return;
        }

        while (($line = fgets($handle)) !== false) {
            try {
                $this->processLine($line);
            } catch (RuntimeException $e) {
                // Expected exception to break the loop cleanly
                // We might want to show the daily total if it wasn't shown?
                // The logic in processLine handles it before throwing.
                break;
            }
        }

        fclose($handle);

        // Show total for the last day processed if any
        if ($this->dayTotalTime > 0) {
            $this->showDailyTotal();
        }

        $this->showFinalSummary();
    }

    /**
     * Process a single line from the timesheet file.
     * 
     * @param string $line The line to process.
     * 
     * @throws RuntimeException If the line belongs to a previous period.
     */
    private function processLine(string $line): void
    {
        $line = trim($line);

        if ($this->isToSkip($line)) {
            return;
        }

        if ($this->isStartTime($line)) {
            if ($this->dayTotalTime > 0) {
                $this->showDailyTotal();
            }

            $this->writeString("------------------------------------------");
            $this->writeString($this->period . "/" . $this->currentDay . ":");
            return;
        }

        if ($this->breakByPeriod($line)) {
             // If we hit a previous month, we might want to stop or just skip. 
             // The original script broke execution. 
             // However, to ensure we process the last day correctly before breaking, 
             // we should check if we need to output the daily total.
             // But since we are inside a loop, the daily total for the *current* day (which belongs to the *current* period)
             // would have been accumulating. If we hit a line from a *previous* period, it means we are done with the current period.
             // The original script logic for `breakByPeriod` was inside the loop.
             // If we encounter a date from a previous period, we should stop processing.
             if ($this->dayTotalTime > 0) {
                 $this->showDailyTotal();
             }
             // We can't easily "break" from here without throwing or returning a status, 
             // but since we read line by line, we can just set a flag or exit.
             // For now, let's just stop reading by closing the file? No, that's messy.
             // Let's just return and let the loop continue but we need to signal to stop.
             // Actually, the cleanest way in this structure is to throw an exception or handle it in the loop.
             // But wait, `processLine` is called in a loop.
             // Let's throw a special exception to break the loop.
             throw new RuntimeException("End of selected period reached.");
        }

        // It's a project line
        $this->processProjectLine($line);
    }

    /**
     * Check if a line should be skipped.
     * 
     * @param string $line The line to check.
     * 
     * @return bool True if the line should be skipped, false otherwise.
     */
    private function isToSkip(string $line): bool
    {
        $toSkip = [
            "##################################################",
            ""
        ];
        return empty($line) || in_array($line, $toSkip, true);
    }

    /**
     * Check if a line is a start time line.
     * 
     * @param string $line The line to check.
     * 
     * @return bool True if the line is a start time line, false otherwise.
     */
    private function isStartTime(string $line): bool
    {
        if (preg_match($this->startProjectString, $line, $match)) {
            if ($this->currentDay !== $match[1]) {
                $this->lastDay = $this->currentDay;
            }
            $this->currentDay = $match[1];
            return true;
        }
        return false;
    }

    /**
     * Check if a line belongs to a previous period.
     * 
     * @param string $line The line to check.
     * 
     * @return bool True if the line belongs to a previous period, false otherwise.
     */
    private function breakByPeriod(string $line): bool
    {
        $prevPeriodInt = (int)$this->period - 1;
        // Handle January going back to December? Original script didn't seem to handle year rollover explicitly for this check,
        // it just checked period - 1. Assuming simple logic for now as per original.
        if ($prevPeriodInt === 0) {
             $prevPeriodInt = 12;
        }
        $prevPeriod = sprintf(self::PERIOD_REGEX, str_pad((string)$prevPeriodInt, 2, '0', STR_PAD_LEFT));

        return (bool)preg_match($prevPeriod, $line);
    }

    /**
     * Process a project line.
     * 
     * @param string $line The project line to process.
     */
    private function processProjectLine(string $line): void
    {
        // Format: Project: @location: #task: desc: time | #task2: desc: time
        $parts = explode(": ", $line, 2);
        if (count($parts) < 2) {
            return; 
        }

        $project = $parts[0];
        $rest = $parts[1];

        // Remove location tags
        $filteredRest = str_replace(["@smartworking:", "@office:"], "", $rest);
        
        $taskLines = array_filter(explode(self::TASK_SEPARATOR, $filteredRest));
        
        $projectLineOutput = "\n" . $project . ": ";
        $firstTask = true;

        $projectTimeSpent = 0.0;

        foreach ($taskLines as $taskLine) {
            $taskLine = trim($taskLine);
            
            // Extract task name
            preg_match(self::TASK_PATTERN, $taskLine, $matchedTasks);
            $taskName = $matchedTasks[1] ?? 'Other';

            // Extract time
            $timeSpentOnTask = 0.0;
            $cleanTaskLine = $taskLine;

            foreach (self::TIME_VAR as $unit => $divisor) {
                // Regex to find time like 1h or 15m
                // The original regex was "/.[[:digit:]]".$time."/" which is a bit loose.
                // Let's try to be more specific: number + unit
                $regex = "/(\d+)\s*" . $unit . "/";
                if (preg_match_all($regex, $taskLine, $matches)) {
                    foreach ($matches[1] as $val) {
                        if ($unit === "h") {
                            $timeSpentOnTask += (float)$val * $divisor; // 1 * 1 = 1h
                        } elseif ($unit === "m") {
                            $timeSpentOnTask += (float)$val / $divisor; // 15 / 60 = 0.25h
                        }
                    }
                    // Remove time from string for output
                    $cleanTaskLine = preg_replace($regex, "", $cleanTaskLine);
                }
            }
            
            // Clean up the line for output
            $cleanTaskLine = str_replace([$taskName . ":", $taskName], "", $cleanTaskLine);
            $cleanTaskLine = trim($cleanTaskLine, " :|");

            // Accumulate stats
            if (!isset($this->tasks[$project][$taskName])) {
                $this->tasks[$project][$taskName] = 0.0;
            }
            $this->tasks[$project][$taskName] += $timeSpentOnTask;
            
            $projectTimeSpent += $timeSpentOnTask;

            // Build output string (just the description, no time)
            // Original requirement: "mostra la descrizione del task senza mostrare le ore specifiche di quel task"
            if (!$firstTask) {
                $projectLineOutput .= " | ";
            }
            $projectLineOutput .= $taskName . ": " . $cleanTaskLine;
            $firstTask = false;
        }

        $this->writeString($projectLineOutput);
        $this->writeString("> Total: " . $projectTimeSpent . "h");

        if (!isset($this->projectsTotalTime[$project])) {
            $this->projectsTotalTime[$project] = 0.0;
        }
        $this->projectsTotalTime[$project] += $projectTimeSpent;
        $this->dayTotalTime += $projectTimeSpent;
    }

    /**
     * Show the daily total.
     */
    private function showDailyTotal(): void
    {
        $this->writeString("");
        $this->writeString("Daily total: " . $this->dayTotalTime . "h");
        $this->writeString("");
        $this->dayTotalTime = 0;
    }

    /**
     * Show the final summary.
     */
    private function showFinalSummary(): void
    {
        if (empty($this->projectsTotalTime)) {
            return;
        }

        $this->writeString("***************************");
        $this->writeString("Total time worked:");
        
        foreach ($this->projectsTotalTime as $project => $time) {
            $this->writeString("- " . $project . ": " . $time . "h");
            
            if (!isset($this->tasks[$project])) {
                continue;
            }

            $totalTasksTime = 0.0;
            foreach ($this->tasks[$project] as $task => $taskTime) {
                $this->writeString("  - " . $task . ": " . $taskTime . "h");
                $totalTasksTime += $taskTime;
            }
            
            // Check for discrepancy (should be minimal with float but good to have)
            $other = $time - $totalTasksTime;
            if ($other > 0.01) { // Tolerance for float comparison
                $this->writeString("  - Other: " . $other . "h");
            }
        }
    }

    /**
     * Write a string to the output.
     * 
     * @param string $string The string to write.
     */
    private function writeString(string $string): void
    {
        echo $string . "\n";
    }
}

// --- Main Execution ---

function getArgs(): array
{
    $validArgs = ['period', 'file'];
    $runner = $_SERVER['argv'] ?? [];
    $args = [];

    // Skip script name
    array_shift($runner);

    foreach ($runner as $arg) {
        if (strpos($arg, '=') === false) {
            continue;
        }
        [$key, $value] = explode("=", $arg, 2);
        if (in_array($key, $validArgs, true)) {
            $args[$key] = $value;
        }
    }
    return $args;
}

try {
    $args = getArgs();
    $app = new Timesheet($args);
    $app->run();
} catch (Exception $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
}