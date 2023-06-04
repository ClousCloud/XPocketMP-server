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

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\FortuneTrait;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use function mt_rand;

class Potato extends Crops{
	use FortuneTrait;

	public function getPickedItem(bool $addUserData = false) : Item{
		return VanillaItems::POTATO();
	}

	/**
	 * @return Item[]
	 */
	protected function getFortuneDropsForLevel(int $level) : array{
		if ($this->age >= self::MAX_AGE) {
			$result = $this->binomialDrops(
				VanillaItems::POTATO(),
				$level,
				1
			);
		} else {
			$result = [
				VanillaItems::POTATO()
			];
		}
		if($this->age >= self::MAX_AGE && mt_rand(0, 49) === 0){
			$result[] = VanillaItems::POISONOUS_POTATO();
		}
		return $result;
	}

	public function asItem() : Item{
		return VanillaItems::POTATO();
	}
}
