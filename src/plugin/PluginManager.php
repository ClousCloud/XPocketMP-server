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

namespace pocketmine\plugin;

use pocketmine\event\Cancellable;
use pocketmine\event\Event;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\Listener;
use pocketmine\event\ListenerMethodTags;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\event\plugin\PluginEnableEvent;
use pocketmine\event\RegisteredListener;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissionManager;
use pocketmine\permission\PermissionParser;
use pocketmine\Server;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Utils;
use Webmozart\PathUtil\Path;
use function array_key_exists;
use function array_keys;
use function array_push;
use function array_search;
use function array_slice;
use function array_unique;
use function class_exists;
use function count;
use function dirname;
use function file_exists;
use function get_class;
use function implode;
use function is_a;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function is_subclass_of;
use function iterator_to_array;
use function mkdir;
use function realpath;
use function shuffle;
use function sprintf;
use function strtolower;

/**
 * Manages all the plugins
 */
class PluginManager{

	/** @var Server */
	private $server;

	/** @var Plugin[] */
	protected $plugins = [];

	/** @var Plugin[] */
	protected $enabledPlugins = [];

	private bool $loadPluginsGuard = false;

	/**
	 * @var PluginLoader[]
	 * @phpstan-var array<class-string<PluginLoader>, PluginLoader>
	 */
	protected $fileAssociations = [];

	/** @var string|null */
	private $pluginDataDirectory;
	/** @var PluginGraylist|null */
	private $graylist;

	public function __construct(Server $server, ?string $pluginDataDirectory, ?PluginGraylist $graylist = null){
		$this->server = $server;
		$this->pluginDataDirectory = $pluginDataDirectory;
		if($this->pluginDataDirectory !== null){
			if(!file_exists($this->pluginDataDirectory)){
				@mkdir($this->pluginDataDirectory, 0777, true);
			}elseif(!is_dir($this->pluginDataDirectory)){
				throw new \RuntimeException("Plugin data path $this->pluginDataDirectory exists and is not a directory");
			}
		}

		$this->graylist = $graylist;
	}

	public function getPlugin(string $name) : ?Plugin{
		if(isset($this->plugins[$name])){
			return $this->plugins[$name];
		}

		return null;
	}

	public function registerInterface(PluginLoader $loader) : void{
		$this->fileAssociations[get_class($loader)] = $loader;
	}

	/**
	 * @return Plugin[]
	 */
	public function getPlugins() : array{
		return $this->plugins;
	}

	private function getDataDirectory(string $pluginPath, string $pluginName) : string{
		if($this->pluginDataDirectory !== null){
			return Path::join($this->pluginDataDirectory, $pluginName);
		}
		return Path::join(dirname($pluginPath), $pluginName);
	}

	private function internalLoadPlugin(string $path, PluginLoader $loader, PluginDescription $description) : ?Plugin{
		$language = $this->server->getLanguage();
		$this->server->getLogger()->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_load($description->getFullName())));

		$dataFolder = $this->getDataDirectory($path, $description->getName());
		if(file_exists($dataFolder) && !is_dir($dataFolder)){
			$this->server->getLogger()->error($language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
				$description->getName(),
				KnownTranslationFactory::pocketmine_plugin_badDataFolder($dataFolder)
			)));
			return null;
		}
		if(!file_exists($dataFolder)){
			mkdir($dataFolder, 0777, true);
		}

		$prefixed = $loader->getAccessProtocol() . $path;
		$loader->loadPlugin($prefixed);

		$mainClass = $description->getMain();
		if(!class_exists($mainClass, true)){
			$this->server->getLogger()->error($language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
				$description->getName(),
				KnownTranslationFactory::pocketmine_plugin_mainClassNotFound()
			)));
			return null;
		}
		if(!is_a($mainClass, Plugin::class, true)){
			$this->server->getLogger()->error($language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
				$description->getName(),
				KnownTranslationFactory::pocketmine_plugin_mainClassWrongType(Plugin::class)
			)));
			return null;
		}

		$permManager = PermissionManager::getInstance();
		foreach($description->getPermissions() as $permsGroup){
			foreach($permsGroup as $perm){
				if($permManager->getPermission($perm->getName()) !== null){
					$this->server->getLogger()->error($language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
						$description->getName(),
						KnownTranslationFactory::pocketmine_plugin_duplicatePermissionError($perm->getName())
					)));
					return null;
				}
			}
		}
		$opRoot = $permManager->getPermission(DefaultPermissions::ROOT_OPERATOR);
		$everyoneRoot = $permManager->getPermission(DefaultPermissions::ROOT_USER);
		foreach(Utils::stringifyKeys($description->getPermissions()) as $default => $perms){
			foreach($perms as $perm){
				$permManager->addPermission($perm);
				switch($default){
					case PermissionParser::DEFAULT_TRUE:
						$everyoneRoot->addChild($perm->getName(), true);
						break;
					case PermissionParser::DEFAULT_OP:
						$opRoot->addChild($perm->getName(), true);
						break;
					case PermissionParser::DEFAULT_NOT_OP:
						//TODO: I don't think anyone uses this, and it currently relies on some magic inside PermissibleBase
						//to ensure that the operator override actually applies.
						//Explore getting rid of this.
						//The following grants this permission to anyone who has the "everyone" root permission.
						//However, if the operator root node (which has higher priority) is present, the
						//permission will be denied instead.
						$everyoneRoot->addChild($perm->getName(), true);
						$opRoot->addChild($perm->getName(), false);
						break;
					default:
						break;
				}
			}
		}

		/**
		 * @var Plugin $plugin
		 * @see Plugin::__construct()
		 */
		$plugin = new $mainClass($loader, $this->server, $description, $dataFolder, $prefixed, new DiskResourceProvider($prefixed . "/resources/"));
		$this->plugins[$plugin->getDescription()->getName()] = $plugin;

		return $plugin;
	}

	/**
	 * @param string          $path
	 * @param PluginLoadOrder $loadingStage
	 * @param string[]|null $newLoaders
	 * @phpstan-param list<class-string<PluginLoader>> $newLoaders
	 *
	 * @return Plugin[]
	 */
	private function internalLoadPlugins(string $path, PluginLoadOrder $loadingStage, ?array $newLoaders = null) : array{
		if(is_array($newLoaders)){
			$loaders = [];
			foreach($newLoaders as $key){
				if(isset($this->fileAssociations[$key])){
					$loaders[$key] = $this->fileAssociations[$key];
				}
			}
		}else{
			$loaders = $this->fileAssociations;
		}

		if(is_dir($path)){
			$files = iterator_to_array(new \FilesystemIterator($path, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS));
			shuffle($files); //this prevents plugins implicitly relying on the filesystem name order when they should be using dependency properties
		}elseif(is_file($path)){
			$realPath = Utils::assumeNotFalse(realpath($path), "realpath() should not return false on an accessible, existing file");
			$files = [$realPath];
		}else{
			return [];
		}

		$loadedPlugins = [];
		$loadabilityChecker = new PluginLoadabilityChecker($this->server->getApiVersion());
		foreach($loaders as $loader){
			foreach($files as $file){
				if(!is_string($file)) throw new AssumptionFailedError("FilesystemIterator current should be string when using CURRENT_AS_PATHNAME");
				if(!$loader->canLoadPlugin($file)){
					continue;
				}
				try{
					$description = $loader->getPluginDescription($file);
				}catch(PluginDescriptionParseException $e){
					$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
						$file,
						KnownTranslationFactory::pocketmine_plugin_invalidManifest($e->getMessage())
					)));
					continue;
				}catch(\RuntimeException $e){ //TODO: more specific exception handling
					$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError($file, $e->getMessage())));
					$this->server->getLogger()->logException($e);
					continue;
				}
				if($description === null || !$description->getOrder()->equals($loadingStage)){
					continue;
				}

				$name = $description->getName();

				if(($loadabilityError = $loadabilityChecker->check($description)) !== null){
					$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError($name, $loadabilityError)));
					continue;
				}

				if(isset($this->plugins[$name])){
					$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_duplicateError($name)));
					continue;
				}

				if(strpos($name, " ") !== false){
					$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_spacesDiscouraged($name)));
				}

				if($this->graylist !== null && !$this->graylist->isAllowed($name)){
					$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
						$name,
						$this->graylist->isWhitelist() ? KnownTranslationFactory::pocketmine_plugin_disallowedByWhitelist() : KnownTranslationFactory::pocketmine_plugin_disallowedByBlacklist()
					)));
					continue;
				}

				$loadedPlugins[$name] = $this->internalLoadPlugin($file, $loader, $description);
			}
		}
		return $loadedPlugins;
	}

	/**
	 * @return Plugin[]
	 */
	public function loadPlugins(string $path, PluginLoadOrder $loadingStage) : array{
		if($this->loadPluginsGuard){
			throw new \LogicException(__METHOD__ . "() cannot be called from within itself");
		}
		$this->loadPluginsGuard = true;

		$newLoaders = null;
		$loadedPlugins = [];
		do{
			$oldRegisteredLoaders = $this->fileAssociations;
			$newPlugins = $this->internalLoadPlugins($path, $loadingStage, $newLoaders);
			if($newLoaders !== null){
				$this->server->getLogger()->debug("New loaders found plugins: " . implode(", ", array_keys($newPlugins)));
			}

			array_push($loadedPlugins, ...$newPlugins);

			$newLoaders = [];
			foreach($this->fileAssociations as $k => $loader){
				if(!array_key_exists($k, $oldRegisteredLoaders)){
					$newLoaders[] = $k;
				}
			}
		}while(count($newLoaders) > 0);

		$this->loadPluginsGuard = false;
		return $loadedPlugins;
	}

	/**
	 * @param Plugin $plugin
	 * @param array  &$triagedPlugins
	 *
	 * @return string[]
	 */
	private function triagePlugin(Plugin $plugin, array &$triagedPlugins) : array{
		if(isset($triagedPlugins[$plugin->getName()])) {
			if($triagedPlugins[$plugin->getName()] === null) { // Circular dependency
				$this->server->getLogger()->critical($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError($plugin->getName(), KnownTranslationFactory::pocketmine_plugin_circularDependency())));
			}
			$triagedPlugins[$plugin->getName()] = false;
			return []; // Already tried to enable this plugin
		}

		$orderedPlugins = [];
		$triagedPlugins[$plugin->getName()] = null;
		foreach($plugin->getDescription()->getDepend() as $hardDependency){
			if(!isset($this->plugins[$hardDependency])){
				$this->server->getLogger()->critical($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError($plugin->getName(), KnownTranslationFactory::pocketmine_plugin_unknownDependency($hardDependency))));
				$triagedPlugins[$plugin->getName()] = false;
				return []; // Missing dependency, can't enable
			}
			$orderedPlugins = $this->triagePlugin($this->plugins[$hardDependency], $triagedPlugins);
			if($triagedPlugins[$hardDependency] === false){
				$this->server->getLogger()->critical($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError($plugin->getName(), KnownTranslationFactory::pocketmine_plugin_unknownDependency($hardDependency))));
				$triagedPlugins[$plugin->getName()] = false;
				return []; // Dependency failed to enable, so this plugin can't either
			}
		}
		$triagedPlugins[$plugin->getName()] = true;
		foreach($plugin->getDescription()->getSoftDepend() as $softDependency){
			if(isset($this->plugins[$softDependency])){
				array_push($orderedPlugins, ...$this->triagePlugin($this->plugins[$softDependency], $triagedPlugins));
			}
		}
		$orderedPlugins[] = $plugin->getName();
		foreach($plugin->getDescription()->getEnableBefore() as $postEnable){
			$key = array_search($postEnable, $orderedPlugins, true); // check if the specified plugin will be enabled before the current one
			if($key !== false) { // If the specified plugin will not be enabled before the current plugin, we don't care
				$orderedPlugins = array_slice($orderedPlugins, 0, $key);
				$orderedPlugins[] = $postEnable;
				$after = array_slice($orderedPlugins, $key + 1);
				array_push($orderedPlugins, ...$after);
			}
		}
		return $orderedPlugins;
	}

	public function enablePlugins() : void{
		if($this->loadPluginsGuard){
			throw new \LogicException(__METHOD__ . "() cannot be called from within itself");
		}
		$this->loadPluginsGuard = true;

		$orderedPlugins = [];
		$triagedPlugins = [];
		foreach($this->plugins as $plugin){
			array_push($orderedPlugins, ...$this->triagePlugin($plugin, $triagedPlugins));
		}
		foreach(array_unique($orderedPlugins) as $pluginName){
			if(!$this->plugins[$pluginName]->isEnabled()){
				$this->enablePlugin($this->plugins[$pluginName]);
			}
		}
		$this->loadPluginsGuard = false;
	}

	public function isPluginEnabled(Plugin $plugin) : bool{
		return isset($this->plugins[$plugin->getDescription()->getName()]) && $plugin->isEnabled();
	}

	public function enablePlugin(Plugin $plugin) : void{
		if(!$plugin->isEnabled()){
			$this->server->getLogger()->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_enable($plugin->getDescription()->getFullName())));

			$plugin->getScheduler()->setEnabled(true);
			$plugin->onEnableStateChange(true);
			if($plugin->isEnabled()){ //the plugin may have disabled itself during onEnable()
				$this->enabledPlugins[$plugin->getDescription()->getName()] = $plugin;

				(new PluginEnableEvent($plugin))->call();
			}
		}
	}

	public function disablePlugins() : void{
		foreach($this->getPlugins() as $plugin){
			$this->disablePlugin($plugin);
		}
	}

	public function disablePlugin(Plugin $plugin) : void{
		if($plugin->isEnabled()){
			$this->server->getLogger()->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_disable($plugin->getDescription()->getFullName())));
			(new PluginDisableEvent($plugin))->call();

			unset($this->enabledPlugins[$plugin->getDescription()->getName()]);

			$plugin->onEnableStateChange(false);
			$plugin->getScheduler()->shutdown();
			HandlerListManager::global()->unregisterAll($plugin);
		}
	}

	public function tickSchedulers(int $currentTick) : void{
		foreach($this->enabledPlugins as $p){
			$p->getScheduler()->mainThreadHeartbeat($currentTick);
		}
	}

	public function clearPlugins() : void{
		$this->disablePlugins();
		$this->plugins = [];
		$this->enabledPlugins = [];
		$this->fileAssociations = [];
	}

	/**
	 * Returns whether the given ReflectionMethod could be used as an event handler. Used to filter methods on Listeners
	 * when registering.
	 *
	 * Note: This DOES NOT validate the listener annotations; if this method returns false, the method will be ignored
	 * completely. Invalid annotations on candidate listener methods should result in an error, so those aren't checked
	 * here.
	 *
	 * @phpstan-return class-string<Event>|null
	 */
	private function getEventsHandledBy(\ReflectionMethod $method) : ?string{
		if($method->isStatic() || !$method->getDeclaringClass()->implementsInterface(Listener::class)){
			return null;
		}
		$tags = Utils::parseDocComment((string) $method->getDocComment());
		if(isset($tags[ListenerMethodTags::NOT_HANDLER])){
			return null;
		}

		$parameters = $method->getParameters();
		if(count($parameters) !== 1){
			return null;
		}

		$paramType = $parameters[0]->getType();
		//isBuiltin() returns false for builtin classes ..................
		if(!$paramType instanceof \ReflectionNamedType || $paramType->isBuiltin()){
			return null;
		}

		/** @phpstan-var class-string $paramClass */
		$paramClass = $paramType->getName();
		$eventClass = new \ReflectionClass($paramClass);
		if(!$eventClass->isSubclassOf(Event::class)){
			return null;
		}

		/** @var \ReflectionClass<Event> $eventClass */
		return $eventClass->getName();
	}

	/**
	 * Registers all the events in the given Listener class
	 *
	 * @throws PluginException
	 */
	public function registerEvents(Listener $listener, Plugin $plugin) : void{
		if(!$plugin->isEnabled()){
			throw new PluginException("Plugin attempted to register " . get_class($listener) . " while not enabled");
		}

		$reflection = new \ReflectionClass(get_class($listener));
		foreach($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method){
			$tags = Utils::parseDocComment((string) $method->getDocComment());
			if(isset($tags[ListenerMethodTags::NOT_HANDLER]) || ($eventClass = $this->getEventsHandledBy($method)) === null){
				continue;
			}
			$handlerClosure = $method->getClosure($listener);
			if($handlerClosure === null) throw new AssumptionFailedError("This should never happen");

			try{
				$priority = isset($tags[ListenerMethodTags::PRIORITY]) ? EventPriority::fromString($tags[ListenerMethodTags::PRIORITY]) : EventPriority::NORMAL;
			}catch(\InvalidArgumentException $e){
				throw new PluginException("Event handler " . Utils::getNiceClosureName($handlerClosure) . "() declares invalid/unknown priority \"" . $tags[ListenerMethodTags::PRIORITY] . "\"");
			}

			$handleCancelled = false;
			if(isset($tags[ListenerMethodTags::HANDLE_CANCELLED])){
				if(!is_a($eventClass, Cancellable::class, true)){
					throw new PluginException(sprintf(
						"Event handler %s() declares @%s for non-cancellable event of type %s",
						Utils::getNiceClosureName($handlerClosure),
						ListenerMethodTags::HANDLE_CANCELLED,
						$eventClass
					));
				}
				switch(strtolower($tags[ListenerMethodTags::HANDLE_CANCELLED])){
					case "true":
					case "":
						$handleCancelled = true;
						break;
					case "false":
						break;
					default:
						throw new PluginException("Event handler " . Utils::getNiceClosureName($handlerClosure) . "() declares invalid @" . ListenerMethodTags::HANDLE_CANCELLED . " value \"" . $tags[ListenerMethodTags::HANDLE_CANCELLED] . "\"");
				}
			}

			$this->registerEvent($eventClass, $handlerClosure, $priority, $plugin, $handleCancelled);
		}
	}

	/**
	 * @param string $event Class name that extends Event
	 *
	 * @phpstan-template TEvent of Event
	 * @phpstan-param class-string<TEvent> $event
	 * @phpstan-param \Closure(TEvent) : void $handler
	 *
	 * @throws \ReflectionException
	 */
	public function registerEvent(string $event, \Closure $handler, int $priority, Plugin $plugin, bool $handleCancelled = false) : RegisteredListener{
		if(!is_subclass_of($event, Event::class)){
			throw new PluginException($event . " is not an Event");
		}

		$handlerName = Utils::getNiceClosureName($handler);

		if(!$plugin->isEnabled()){
			throw new PluginException("Plugin attempted to register event handler " . $handlerName . "() to event " . $event . " while not enabled");
		}

		$timings = new TimingsHandler("Plugin: " . $plugin->getDescription()->getFullName() . " Event: " . $handlerName . "(" . (new \ReflectionClass($event))->getShortName() . ")");

		$registeredListener = new RegisteredListener($handler, $priority, $plugin, $handleCancelled, $timings);
		HandlerListManager::global()->getListFor($event)->register($registeredListener);
		return $registeredListener;
	}
}
