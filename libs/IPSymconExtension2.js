/*
 IPSymconExtension
 Version: 4.6
*/

const utils = require("../util/utils");

class MyLogger {
    constructor(logger) {
        // Das von Zigbee2MQTT übergebene logger-Objekt (ggf. leer)
        this.logger = logger || {};
    }

    info(...args) {
        if (typeof this.logger.info === 'function') {
            // Logger von Z2M nutzen
            this.logger.info(...args);
        } else {
            // Fallback: auf console.log
            console.log('[MyExtension][info]', ...args);
        }
    }

    error(...args) {
        if (typeof this.logger.error === 'function') {
            this.logger.error(...args);
        } else {
            console.error('[MyExtension][error]', ...args);
        }
    }

    debug(...args) {
        if (typeof this.logger.debug === 'function') {
            this.logger.debug(...args);
        } else {
            console.log('[MyExtension][debug]', ...args);
        }
    }
}

class IPSymconExtension {
    constructor(zigbee, mqtt, state, publishEntityState, eventBus, enableDisableExtension, restartCallback, addExtension, settings, baseLogger) {
        this.zigbee = zigbee;
        this.mqtt = mqtt;
        this.state = state;
        this.publishEntityState = publishEntityState;
        this.eventBus = eventBus;
        this.settings = settings;
        this.logger = new MyLogger(baseLogger);
        this.baseTopic = this.settings.get().mqtt.base_topic;
        this.symconExtensionTopic = 'SymconExtension';
        this.eventBus.onMQTTMessage(this, this.onMQTTMessage.bind(this));
        this.logger.info('Loaded IP-Symcon Extension');
    }

    async start() {
        this.mqtt.subscribe(`${this.baseTopic}/${this.symconExtensionTopic}/#`);

    }

    async onMQTTMessage(data) {
        if (data.topic.startsWith(`${this.baseTopic}/${this.symconExtensionTopic}/request/getDeviceInfo/`)) {
            try {
                const devicename = data.topic.split('/').slice(4).join('/');
                const message = JSON.parse(data.message);
                const device = this.zigbee.resolveEntity(devicename);
                let devicepayload = {};
                if (device) {
                    devicepayload = this.#createDevicePayload(device, true);
                }
                devicepayload.transaction = message.transaction;
                this.logger.info('Symcon: request/getDevice');
                await this.mqtt.publish(`${this.symconExtensionTopic}/response/getDeviceInfo/${devicename}`, JSON.stringify(devicepayload));
            } catch (error) {
                let errormessage = 'Unknown Error'
                if (error instanceof Error) errormessage = error.message
                this.logger.error(`Symcon error (${errormessage}) at Topic ${data.topic}`);
            }
            return;
        }
        if (data.topic.startsWith(`${this.baseTopic}/${this.symconExtensionTopic}/request/getGroupInfo`)) {
            try {
                const groupname = data.topic.split('/').slice(4).join('/');
                const message = JSON.parse(data.message);
                const groupExposes = this.#createGroupExposes(groupname);
                groupExposes.transaction = message.transaction;
                this.logger.info('Symcon: request/getGroupe');
                await this.mqtt.publish(`${this.symconExtensionTopic}/response/getGroupInfo/${groupname}`, JSON.stringify(groupExposes));
            } catch (error) {
                let errormessage = 'Unknown Error'
                if (error instanceof Error) errormessage = error.message
                this.logger.error(`Symcon error (${errormessage}) at Topic ${data.topic}`);
            }
            return;
        }
        if (data.topic == `${this.baseTopic}/${this.symconExtensionTopic}/lists/request/getGroups`) {
            try {
                const message = JSON.parse(data.message);
                const groups = {
                    list: [],
                    transaction: 0,
                };
                for (const group of this.zigbee.groupsIterator()) {
                    const listEntry = {
                        devices: [],
                        friendly_name: group.options.friendly_name,
                        ID: group.zh.groupID
                    }
                    for (const device of group.zh.members) {
                        listEntry.devices.push(device.deviceIeeeAddress);
                    }
                    groups.list.push(listEntry);
                }
                groups.transaction = message.transaction;
                this.logger.info('Symcon: lists/request/getGroups');
                await this.mqtt.publish(`${this.symconExtensionTopic}/lists/response/getGroups`, JSON.stringify(groups));
            } catch (error) {
                let errormessage = 'Unknown Error'
                if (error instanceof Error) errormessage = error.message
                this.logger.error(`Symcon error (${errormessage}) at Topic ${data.topic}`);
            }
            return;
        }
        if (data.topic == `${this.baseTopic}/${this.symconExtensionTopic}/lists/request/getDevices`) {
            try {
                const message = JSON.parse(data.message);
                const devices = {
                    list: [],
                    transaction: 0,
                };
                try {
                    for (const device of this.zigbee.devicesIterator(utils.deviceNotCoordinator)) {
                        devices.list = devices.list.concat(this.#createDevicePayload(device, false));
                    }
                } catch (error) {
                    devices.list = this.zigbee.devices(false).map(device => this.#createDevicePayload(device, false));
                }
                devices.transaction = message.transaction;
                this.logger.info('Symcon: lists/request/getDevices');
                await this.mqtt.publish(`${this.symconExtensionTopic}/lists/response/getDevices`, JSON.stringify(devices));
            } catch (error) {
                let errormessage = 'Unknown Error'
                if (error instanceof Error) errormessage = error.message
                this.logger.error(`Symcon error (${errormessage}) at Topic ${data.topic}`);
            }
            return;
        }
    }

    async stop() {
        this.eventBus.removeListeners(this);
    }

    #createDevicePayload(device, boolExposes) {
        let exposes;
        if (boolExposes) {
            exposes = device.exposes();
        }
        return {
            ieeeAddr: device.ieeeAddr,
            type: device.zh.type,
            networkAddress: device.zh.networkAddress,
            model: device.definition?.model ?? 'Unknown Model',
            vendor: device.definition?.vendor ?? 'Unknown Vendor',
            description: device.definition?.description ?? 'No description',
            friendly_name: device.name,
            manufacturerName: device.zh.manufacturerName,
            powerSource: device.zh.powerSource,
            modelID: device.zh.modelID,
            exposes: exposes,
        };
    }

    #createGroupExposes(groupname) {
        const groupSupportedTypes = ['light', 'switch', 'lock', 'cover'];
        const groupExposes = { foundGroup: false };
        groupSupportedTypes.forEach(type => groupExposes[type] = { type, features: [] });

        const group = this.zigbee.resolveEntity(groupname);
        if (group) {
            groupExposes.foundGroup = true;
            this.#processGroupDevices(group, groupExposes);
        }
        return groupExposes;
    }

    #processGroupDevices(group, groupExposes) {
        group.zh.members.forEach(member => {
            const device = this.zigbee.resolveEntity(member.deviceIeeeAddress);
            this.#addDeviceExposesToGroup(device, groupExposes);
        });
    }

    #addDeviceExposesToGroup(device, groupExposes) {
        let exposes = [];
        // Überprüfen, ob 'definition' vorhanden ist und Exposes hinzufügen
        if (device.definition && device.definition.exposes) {
            exposes = exposes.concat(device.definition.exposes);
        }

        // Überprüfen, ob '_definition' vorhanden ist und Exposes hinzufügen
        if (device._definition && device._definition.exposes) {
            exposes = exposes.concat(device._definition.exposes);
        }

        // Verarbeite alle gesammelten Exposes
        exposes.forEach(expose => {
            const type = expose.type;
            if (groupExposes[type]) {
                this.#processExposeFeatures(expose, groupExposes[type]);
            }
        });
    }
    #processExposeFeatures(expose, groupExposeType) {
        expose.features.forEach(feature => {
            if (!groupExposeType.features.some(f => f.property === feature.property)) {
                groupExposeType.features.push(feature);
            }
        });
    }
}

module.exports = IPSymconExtension;
