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

use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\EndPortalFrameFillSound;

class EndPortalFrame extends Opaque{
	use FacesOppositePlacingPlayerTrait;

	protected bool $eye = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->eye);
	}

	public function hasEye() : bool{ return $this->eye; }

	/** @return $this */
	public function setEye(bool $eye) : self{
		$this->eye = $eye;
		return $this;
	}

	public function getLightLevel() : int{
		return 1;
	}

	/**
	 * @return AxisAlignedBB[]
	 */
	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 3 / 16)];
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->eye || $item->getTypeId() !== ItemTypeIds::ENDER_EYE){
			return false;
		}
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this->setEye(true));
		$world->addSound($this->position, new EndPortalFrameFillSound());
		$item->pop();

		//TODO: portal spawn logic

		return true;
	}
}
