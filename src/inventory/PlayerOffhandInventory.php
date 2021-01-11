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

namespace pocketmine\inventory;

use pocketmine\entity\Human;
use pocketmine\item\Item;

final class PlayerOffhandInventory extends BaseInventory{
	/** @var Human */
	private $holder;

	public function __construct(Human $player){
		$this->holder = $player;
		parent::__construct(1);
	}

	public function getDefaultSize() : int{
		return 1;
	}

	public function getHolder() : Human{ return $this->holder; }

	public function getItemInOffhand() : Item{
		return $this->getItem(0);
	}

	public function setItemInOffhand(Item $item){
		$this->setItem(0, $item);
	}

	protected function onSlotChange(int $index, Item $before) : void{
		parent::onSlotChange($index, $before);
		foreach($this->holder->getViewers() as $viewer){
			$viewer->getNetworkSession()->onMobEquipmentChange($this->holder, true, $this->getItem(0));
		}
	}
}
