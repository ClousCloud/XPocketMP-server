<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\event\TimingsHandler;
use pocketmine\command\defaults\Threads\TimingsPasteTask;
use pocketmine\event\TranslationContainer;

class TimingsCommand extends VanillaCommand{

	public static $timingStart = 0;

	public function __construct($name){
		parent::__construct(
			$name,
			"%pocketmine.command.timings.description",
			"%pocketmine.command.timings.usage"
		);
		$this->setPermission("pocketmine.command.timings");
	}

	public function execute(CommandSender $sender, $currentAlias, array $args){
		if(!$this->testPermission($sender)){
			return true;
		}

		if(count($args) !== 1){
			$sender->sendMessage(new TranslationContainer("commands.generic.usage", [$this->usageMessage]));

			return true;
		}

		$mode = strtolower($args[0]);

		if($mode === "on"){
			$sender->getServer()->getPluginManager()->setUseTimings(true);
			TimingsHandler::reload();
			$sender->sendMessage(new TranslationContainer("pocketmine.command.timings.enable"));

			return true;
		}elseif($mode === "off"){
			$sender->getServer()->getPluginManager()->setUseTimings(false);
			$sender->sendMessage(new TranslationContainer("pocketmine.command.timings.disable"));
			return true;
		}

		if(!$sender->getServer()->getPluginManager()->useTimings()){
			$sender->sendMessage(new TranslationContainer("pocketmine.command.timings.timingsDisabled"));

			return true;
		}

		$paste = $mode === "paste";

		if($mode === "reset"){
			TimingsHandler::reload();
			$sender->sendMessage(new TranslationContainer("pocketmine.command.timings.reset"));
		}elseif($mode === "merged" or $mode === "report" or $paste){

			$sampleTime = microtime(true) - self::$timingStart;
			$index = 0;
			$timingFolder = $sender->getServer()->getDataPath() . "timings/";

			if(!file_exists($timingFolder)){
				mkdir($timingFolder, 0777);
			}
			$timings = $timingFolder . "timings.txt";
			while(file_exists($timings)){
				$timings = $timingFolder . "timings" . (++$index) . ".txt";
			}

			$fileTimings = $paste ? fopen("php://temp", "r+b") : fopen($timings, "a+b");

			TimingsHandler::printTimings($fileTimings);

			fwrite($fileTimings, "Sample time " . round($sampleTime * 1000000000) . " (" . $sampleTime . "s)" . PHP_EOL);

			if($paste){
				fseek($fileTimings, 0);
				$data = [
					"syntax" => "text",
					"poster" => $sender->getServer()->getName(),
					"content" => stream_get_contents($fileTimings)
				];

				$sender->sendMessage("Processing timings paste request...");

				$sender->getServer()->getScheduler()->scheduleAsyncTask(new TimingsPasteTask($sender->getName(), serialize($data), $sender->getServer()->getName(), $sender->getServer()->getPocketMineVersion()));

				fclose($fileTimings);
			}else{
				fclose($fileTimings);
				$sender->sendMessage(new TranslationContainer("pocketmine.command.timings.timingsWrite", [$timings]));
			}
		}

		return true;
	}
}
