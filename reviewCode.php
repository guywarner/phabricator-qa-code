#!/usr/bin/php -q
<?php

class ReviewCode {

	public function run() {
		echo "\nSnails dispatched to get current diffs...\n";

		exec("echo '{\"status\":\"status-open\",\"limit\":\"20\"}' | arc call-conduit differential.query", $output, $ret);

		$revisions = json_decode($output[0], true);
		$possibleIds = array();
		$diffCount = 0;
		foreach ($revisions['response'] as $diff) {
			if ($diff['statusName'] == "Needs Review") {
				echo "\033[31mD" . $diff['id'] . "\033[32m - \033[37m" . $diff['title'] . "\n";
				$diffCount++;
			}
			$possibleIds[] = $diff['id'];
		}
		if ($diffCount == 0) {
			echo "Wahoo! No code to review\n";
			exit;
		}
		$toReviewId = 0;

		while ($toReviewId == 0) {
			echo "\n\033[0mWhich revision ID would you like to review? D";
			$handle = fopen("php://stdin","r");
			$line = fgets($handle);
			$userInput = trim($line);
			if (!is_numeric($userInput)) {
				echo "\n\033[31m$userInput is not a number known to man. Try again.\n";
			} elseif (!in_array($userInput, $possibleIds)) {
				echo "\n\033[31mThat wasn't even an option try again...\n";
			} else {
				$toReviewId = $userInput;
			}
		}
		$output = array();
		exec("git checkout develop");
		exec("git ls-files --others --exclude-standard", $output, $ret);
		exec("git diff --name-only", $output, $ret);

		if (!empty($output)) {
			echo "\nUnstaged/untracked files.... probably need to run 'git reset --hard HEAD'\n";
			echo "\n\033[31mstopping - get your house in order\n";
			exit;
		}
		echo "\n\033[0mRunning arc patch on D$toReviewId\n";
		$output = array();
		exec("arc patch D$toReviewId", $output, $ret);
		exec("./app/Console/cake Migrations.migration run all", $output, $ret);
		foreach ($output as $line) {
			if (strlen($line) < 500) {
				echo $line . "\n";
			}
		}

		$currentRevision = $revisions['response'][array_search($toReviewId, $possibleIds)];

		echo "\n\033[31mTitle:\n";
		echo "\033[37m" . $currentRevision['title'];
		echo "\n\033[31mSummary:\n";
		echo "\033[37m" . $currentRevision['summary'];
		echo "\n\033[31mTest Plan:\n";
		echo "\033[37m" . $currentRevision['testPlan'];
		echo "\n";

		$status = "";
		while ($status == "") {
			echo "\n\033[0mWhat would you like to do to this revision? [r]eject [a]ccept:";
			$handle = fopen("php://stdin","r");
			$line = fgets($handle);
			$userInput = trim($line);

			if ($userInput != "a" && $userInput != "r") {
				echo "There's only two possible answers.  Yours was not one of them.";
			} else {
				$status = $userInput;
			}
		}

		echo "\n\033[0mComment:";
		$handle = fopen("php://stdin","r");
		$line = fgets($handle);
		$userInput = trim($line);
		$comment = htmlspecialchars($userInput);
		if ($status == "a") {
			$action = "accept";
		} elseif ($status == "r") {
			$action = "reject";
		} else {
			echo "Something went wrong";
			exit;
		}

		$output = array();
		exec("echo '{\"revision_id\":\"$toReviewId\",\"message\":\"$comment\",\"action\":\"$action\"}' | arc call-conduit differential.createcomment", $output, $ret);

		if ($status == "a") {
			$toLand = "";
			while ($toLand == "") {
				echo "\n\033[0mWhat would you like land D$toReviewId? [y]es [n]o:";
				$handle = fopen("php://stdin","r");
				$line = fgets($handle);
				$userInput = trim($line);

				if ($userInput != "y" && $userInput != "n") {
					echo "There's only two possible answers.  Yours was not one of them.\n";
				} else {
					$toLand = $userInput;
				}
			}

			if ($toLand == "y") {
				echo "Checking out develop\n";
				exec("git checkout develop");
				echo "Cleaning up arcpatch branches\n";
				exec("git branch -D `git for-each-ref --format=\"%(refname:short)\" refs/heads/arcpatch\\*` ");
				echo "Rest to head\n";
				exec("git reset --hard HEAD");
				echo "Making sure we are up to date\n";
				exec("git pull origin develop");
				echo "Patching\n";
				exec("arc patch D$toReviewId --nobranch");
				echo "Pushing up\n";
				exec("git push origin develop");
				echo "\033[31mHigh probability that this worked\n";
				exit;

			}
		}
	}

}

$reviewCode = new ReviewCode();
$reviewCode->run();

