(function () {
    "use strict";

    window.FormItemResultTable = {

        process: function (feature, dialog) {


            var item = this;

            var dataStoreLinkName = item.dataStoreLink.name;

            if (dataStoreLinkName) {

                var requestData = {
                    dataStoreLinkName: dataStoreLinkName,
                    fid: feature.fid,
                    fieldName: item.dataStoreLink.fieldName
                };

                QueryEngine.query('dataStore/get', requestData).done(function (data) {

                    if (Array.isArray(data)) {

                        var dataItems = [];
                        _.each(data, function (el, i) {
                            el.attributes.item = item;
                            dataItems.push(el.attributes)

                        });

                    } else {
                        data.item = item;
                    }

                    var tableApi = dialog.find('.mapbender-element-result-table').resultTable('getApi');
                    tableApi.clear();
                    tableApi.rows.add(dataItems);
                    tableApi.draw();

                });
            }
            return this.clone();

        },

        preprocess: function () {
            var item = this;

            if (!item.editable) {
                return item.clone();
            }

            var onCreateClick;
            var onEditClick;

            if (!item.dataManagerLink) {

                onCreateClick = function (e) {
                    e.preventDefault();
                    var item = $(this).next().data("item");
                    var popup = item.popupItems;
                    var table = $(this).siblings(".mapbender-element-result-table");
                    var uniqueIdKey = item.dataStore.uniqueId;

                    var feature = table.data('olFeature');
                    var data = {};

                    item.allowRemove = false;
                    data['linkId'] = feature.attributes[item.dataStoreLink.uniqueId];
                    data.item = item;
                    data[uniqueIdKey] = null;
                    dataManagerUtils.openEditDialog(data, popup, item, table);
                    return false;
                };

                onEditClick = function (rowData, ui, e) {
                    e.defaultPrevented && e.defaultPrevented();
                    e.preventDefault && e.preventDefault();

                    var table = ui.parents('.mapbender-element-result-table');
                    var item = table.data('item');
                    var popup = item.popupItems;
                    var feature = table.data('olFeature');

                    item.allowRemove = true;
                    rowData.externalId = feature.attributes[item.dataStoreLink.uniqueId];

                    dataManagerUtils.openEditDialog(rowData, popup, item, table);

                    return false;
                };
            } else {
                var schemaName = item.dataManagerLink.schema;
                var fieldName = item.dataManagerLink.fieldName;
                var schemaFieldName = item.dataManagerLink.schemaFieldName;

                onCreateClick = function (e) {
                    e.preventDefault && e.preventDefault();

                    dataManager.withSchema(schemaName, function (schema) {
                        dataManager._openEditDialog(schema.create());
                    });

                    return false;
                };

                onEditClick = function (rowData, ui, e) {
                    e.defaultPrevented && e.defaultPrevented();
                    e.preventDefault && e.preventDefault();

                    dataManager.withSchema(schemaName, function (schema) {
                        var dataItem = _.find(schema.dataItems, function (d) {
                            return d[schemaFieldName] === rowData[fieldName];
                        });
                        dataManager._openEditDialog(dataItem);
                    });

                    return false;
                };
            }

            var cloneItem = item.clone();
            Object.setPrototypeOf(cloneItem,FormItemResultTable);
            var button = {
                title: Mapbender.DigitizerTranslator.translate('feature.edit'),
                className: 'edit',
                onClick: onEditClick
            };
            Object.setPrototypeOf(button,FormItemButton);
            cloneItem.buttons = [button];

            // TODO check if otther formElements are used as buttons, not as children



            var containerItem = item.clone();
            Object.setPrototypeOf(containerItem,FormItemContainer);


            var button2 = {
                title: "",
                cssClass: "fa fa-plus",
                click: onCreateClick
            };
            Object.setPrototypeOf(button2,FormItemButton);
            containerItem.children = [button, cloneItem];


            return containerItem;

        }

    };

    Object.setPrototypeOf(FormItemResultTable, FormItem);

})();
