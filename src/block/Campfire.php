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

use pocketmine\block\inventory\CampfireInventory;
use pocketmine\block\tile\Campfire as TileCampfire;
use pocketmine\block\utils\BlockDataSerializer;
use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\crafting\FurnaceRecipe;
use pocketmine\crafting\FurnaceType;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\FlintSteel;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\Shovel;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\CampfireSound;
use pocketmine\world\sound\FireExtinguishSound;
use pocketmine\world\sound\FlintSteelSound;
use function count;
use function mt_rand;

class Campfire extends Transparent{
	use FacesOppositePlacingPlayerTrait;
	use HorizontalFacingTrait;

	protected bool $lit = true;
	protected CampfireInventory $inventory;
	/** @phpstan-var array<int, int> */
	protected array $cookingTimes = [];


	public function writeStateToMeta() : int{
		return BlockDataSerializer::writeHorizontalFacing($this->facing) | (!$this->lit ? BlockLegacyMetadata::CAMPFIRE_FLAG_EXTINGUISHED : 0);
	}

	public function readStateFromData(int $id, int $stateMeta) : void{
		$this->facing = BlockDataSerializer::readHorizontalFacing($stateMeta & 0x03);
		$this->lit = ($stateMeta & BlockLegacyMetadata::CAMPFIRE_FLAG_EXTINGUISHED) !== BlockLegacyMetadata::CAMPFIRE_FLAG_EXTINGUISHED;
	}

	public function readStateFromWorld() : void{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileCampfire){
			$this->inventory = $tile->getInventory();
			$this->cookingTimes = $tile->getCookingTimes();
		}
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileCampfire){
			$tile->setCookingTimes($this->cookingTimes);
		}
	}

	public function getStateBitmask() : int{
		return 0b111;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function getLightLevel() : int{
		return $this->lit ? 15 : 0;
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaItems::CHARCOAL()->setCount(2)
		];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE();
	}

	public function getInventory() : CampfireInventory{
		return $this->inventory;
	}

	public function isLit() : bool{
		return $this->lit;
	}

	/** @return $this */
	public function setLit(bool $lit = true) : self{
		$this->lit = $lit;
		return $this;
	}

	public function getFurnaceType() : FurnaceType{
		return FurnaceType::CAMPFIRE();
	}

	public function setCookingTime(int $slot, int $time) : void{
		$this->cookingTimes[$slot] = $time;
	}

	public function getCookingTime(int $slot) : int{
		return $this->cookingTimes[$slot] ?? 0;
	}

	private function extinguish() : void{
		$this->position->getWorld()->addSound($this->position, new FireExtinguishSound());
		$this->position->getWorld()->setBlock($this->position, $this->setLit(false));
	}

	private function fire() : void{
		$this->position->getWorld()->addSound($this->position, new FlintSteelSound());
		$this->position->getWorld()->setBlock($this->position, $this->setLit());
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(!$this->getSide(Facing::DOWN)->getSupportType(Facing::UP)->hasCenterSupport()){
			return false;
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			if($item instanceof FlintSteel){
				if(!$this->lit){
					$item->applyDamage(1);
					$this->fire();
				}
				return true;
			}
			if($item instanceof Shovel && $this->lit){
				$item->applyDamage(1);
				$this->extinguish();
				return true;
			}

			$ingredient = clone $item;
			$ingredient->setCount(1);
			if($this->inventory->canAddItem($ingredient)){
				$this->inventory->addItem($ingredient);
				$item->pop();
				return true;
			}
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		$block = $this->getSide(Facing::UP);
		if($block instanceof Water && $this->lit){
			$this->extinguish();
		}
	}

	public function onEntityInside(Entity $entity) : bool{
		if(!$this->lit){
			if($entity->isOnFire()){
				$this->fire();
				return true;
			}
			return false;
		}
		if($entity instanceof SplashPotion && $entity->getPotionType()->equals(PotionType::WATER())){
			$this->extinguish();
			return true;
		}elseif($entity instanceof Living){
			$entity->attack(new EntityDamageByBlockEvent($this, $entity, EntityDamageEvent::CAUSE_FIRE, 1));
			$entity->setOnFire(8);
		}
		return false;
	}

	public function onScheduledUpdate() : void{
		if($this->lit){
			$items = $this->inventory->getContents();
			foreach($items as $slot => $item){
				$this->setCookingTime($slot, $this->getCookingTime($slot) + 20);
				if($this->getCookingTime($slot) >= $this->getFurnaceType()->getCookDurationTicks()){
					$this->inventory->setItem($slot, VanillaItems::AIR());
					$this->setCookingTime($slot, 0);
					$result = ($item = $this->position->getWorld()->getServer()->getCraftingManager()->getFurnaceRecipeManager($this->getFurnaceType())->match($item)) instanceof FurnaceRecipe ? $item->getResult() : VanillaItems::AIR();
					$this->position->getWorld()->dropItem($this->position->add(0, 1, 0), $result);
				}
			}
			if(count($items) > 0){
				$this->position->getWorld()->setBlock($this->position, $this);
			}
			if(mt_rand(1, 10) === 1){
				$this->position->getWorld()->addSound($this->position, new CampfireSound());
			}

			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 20);
		}
	}
}
