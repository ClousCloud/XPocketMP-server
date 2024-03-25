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

namespace pocketmine\entity;

use pocketmine\entity\trade\TradeRecipe;
use pocketmine\entity\trade\TradeRecipeData;
use pocketmine\entity\trade\VillagerProfession;
use pocketmine\inventory\TradeInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;

class Villager extends Living implements Ageable{
	public const PROFESSION_FARMER = 0;
	public const PROFESSION_LIBRARIAN = 1;
	public const PROFESSION_PRIEST = 2;
	public const PROFESSION_BLACKSMITH = 3;
	public const PROFESSION_BUTCHER = 4;

	private const TAG_PROFESSION = "Profession"; //TAG_Int

	public static function getNetworkTypeId() : string{ return EntityIds::VILLAGER; }

	private bool $baby = false;
	private VillagerProfession $profession = VillagerProfession::FARMER;

	private TradeRecipeData $data;

	private TradeInventory $inventory;

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.8, 0.6); //TODO: eye height??
	}

	public function getName() : string{
		return "Villager";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->data = new TradeRecipeData([
			new TradeRecipe(VanillaItems::STICK()->setCount(5), VanillaItems::EMERALD()->setCount(1)),
			new TradeRecipe(VanillaItems::EMERALD()->setCount(1), VanillaItems::STICK()->setCount(1))
		], [
			0 => 10
		]); // TODO
		$this->inventory = new TradeInventory($this);

		$profession = VillagerProfession::from($nbt->getInt(self::TAG_PROFESSION, self::PROFESSION_FARMER));

		$this->setVillagerProfession($profession);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_PROFESSION, $this->getProfession());

		return $nbt;
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		return $player->setCurrentWindow($this->inventory);
	}

	/**
	 * @deprecated
	 * @see Villager::setVillagerProfession()
	 */
	public function setProfession(int $profession) : void{
		$this->setVillagerProfession(VillagerProfession::from($profession));
	}

	/**
	 * Sets the villager profession
	 */
	public function setVillagerProfession(VillagerProfession $profession) : void{
		$this->profession = $profession;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * @deprecated
	 * @see Villager::getVillagerProfession()
	 */
	public function getProfession() : int{
		return $this->profession->value;
	}

	public function getVillagerProfession() : VillagerProfession{
		return $this->profession;
	}

	public function isBaby() : bool{
		return $this->baby;
	}

	public function getTradeData() : TradeRecipeData{
		return $this->data;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->baby);

		$properties->setInt(EntityMetadataProperties::VARIANT, $this->profession->value);
	}

	public function getInventory() : TradeInventory{
		return $this->inventory;
	}
}
