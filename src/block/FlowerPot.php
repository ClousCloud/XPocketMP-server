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

use pocketmine\block\tile\FlowerPot as TileFlowerPot;
use pocketmine\block\utils\SlabType;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function assert;

class FlowerPot extends Flowable{

	protected ?Block $plant = null;

	protected function writeStateToMeta() : int{
		//TODO: HACK! this is just to make the client actually render the plant - we purposely don't read the flag back
		return $this->plant !== null ? BlockLegacyMetadata::FLOWER_POT_FLAG_OCCUPIED : 0;
	}

	public function getStateBitmask() : int{
		return 0b1;
	}

	public function readStateFromWorld() : void{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileFlowerPot){
			$this->setPlant($tile->getPlant());
		}else{
			$this->setPlant(null);
		}
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();

		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileFlowerPot);
		$tile->setPlant($this->plant);
	}

	public function getPlant() : ?Block{
		return $this->plant;
	}

	/** @return $this */
	public function setPlant(?Block $plant) : self{
		if($plant === null or $plant instanceof Air){
			$this->plant = null;
		}else{
			$this->plant = clone $plant;
		}
		return $this;
	}

	public function canAddPlant(Block $block) : bool{
		if($this->plant !== null){
			return false;
		}

		return $this->canBePlacedInFlowerPot($block);
	}

	/**
	 * @return AxisAlignedBB[]
	 */
	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(3 / 16, 0, 3 / 16)->trim(Facing::UP, 5 / 8)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(!$this->isValidSupport($this->getSide(Facing::DOWN))){
			return false;
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->isValidSupport($this->getSide(Facing::DOWN))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$plant = $item->getBlock();
		if(!$this->canBePlacedInFlowerPot($plant)){
			$this->setPlant(null);
		}elseif(!$this->canAddPlant($plant)){
			return false;
		}else{
			if($plant instanceof BambooSapling){
				//Hack to convert bamboosapling to bamboo block (different id)
				$plant = VanillaBlocks::BAMBOO();
			}
			$this->setPlant($plant);
			$item->pop();
		}
		$this->position->getWorld()->setBlock($this->position, $this);

		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$items = parent::getDropsForCompatibleTool($item);
		if($this->plant !== null){
			$items[] = $this->plant->asItem();
		}

		return $items;
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		return $this->plant !== null ? $this->plant->asItem() : parent::getPickedItem($addUserData);
	}

	protected function isValidSupport(Block $down): bool{
		if($down instanceof Slab && ($down->getSlabType()->equals(SlabType::TOP()) || $down->getSlabType()->equals(SlabType::DOUBLE()))){
			return true;
		}elseif($down instanceof Stair && $down->isUpsideDown()){
			return true;
		}
		//TODO: piston, dropper
		switch ($down->getId()) {
			case BlockLegacyIds::BEACON:
			case BlockLegacyIds::GLASS:
			case BlockLegacyIds::FARMLAND:
			case BlockLegacyIds::GLOWSTONE:
			case BlockLegacyIds::GRASS_PATH:
			case BlockLegacyIds::HARD_STAINED_GLASS:
			case BlockLegacyIds::HOPPER_BLOCK:
			case BlockLegacyIds::FENCE:
			case BlockLegacyIds::STONE_WALL:
			case BlockLegacyIds::STONE_WALL:
			case BlockLegacyIds::SEA_LANTERN:
				return true;
				break;
		}
		return !$down->isTransparent();
	}

	protected function canBePlacedInFlowerPot(Block $block): bool{
		switch (true) {
			case $block instanceof Cactus:
			case $block instanceof DeadBush:
			case $block instanceof Flower:
			case $block instanceof RedMushroom:
			case $block instanceof BrownMushroom:
			case $block instanceof Sapling:
			case $block instanceof BambooSapling:
			//TODO: roots, azaleas 
				return true;
				break;
			case $block instanceof TallGrass:
				return $block->getIdInfo()->getVariant() === BlockLegacyMetadata::TALLGRASS_FERN;
				break;
		}
		return false;
	}
}
