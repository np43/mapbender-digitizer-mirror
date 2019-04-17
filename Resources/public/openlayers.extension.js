(function () {
    "use strict";

    OpenLayers.Feature.prototype.equals = function (feature) {
        return this.fid === feature.fid;
    };
    OpenLayers.Feature.prototype.isNew = false;
    OpenLayers.Feature.prototype.isChanged = false;
    OpenLayers.Feature.prototype.disabled = false;
    OpenLayers.Feature.prototype.visible = true;
    OpenLayers.Feature.prototype.cluster = false;
    OpenLayers.Feature.prototype.getClusterSize = function () {
        return this.cluster ? this.cluster.length : null;
    };

})();