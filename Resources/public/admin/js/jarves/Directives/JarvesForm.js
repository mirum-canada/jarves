jarves.Directives.JarvesForm = new Class({
    Statics: {
        $inject: ['$scope', '$element', '$attrs', '$compile', '$interpolate']
    },
    JarvesDirective: {
        name: 'jarvesForm',
        options: {
            restrict: 'E',
            scope: {
                'fields': '=',
                'model': '='
            },
            controller: true
        }
    },

    fields: {},
    editController: null,

    initialize: function($scope, $element, $attributes, $compile, $interpolate) {
        this.$scope = $scope;
        this.$element = $element;
        this.$attributes = $attributes;
        this.$compile = $compile;
        this.$interpolate = $interpolate;
        this.editController = $scope.editController;

        if (this.editController) {
            this.editController.addForm(this.getName(), this);
        }
    },

    getName: function() {
        return this.$attrs.name ? this.$interpolate(this.$attrs.name)(this.$scope) : '';
    },

    isValid: function(highlight) {
        var valid = true;
        Object.each(this.fields, function(field) {
            if (!field.isValid(highlight)) {
                valid = false;
            }
        });

        return valid;
    },

    link: function(scope, element, attributes) {
        this.$scope.$watch('fields', function(fields) {
            var xml = this.buildXml(fields, 'fields');
            this.$element.html(xml);
            this.$compile(this.$element.contents())(this.$scope);
        }.bind(this));
    },

    /**
     *
     * @param {jarves.AbstractFieldType} fieldController
     */
    addField: function(fieldController) {
        this.fields[fieldController.getId()] = fieldController;
    },

    buildXml: function(fields, parentModelName, depth) {
        var xml = [];

        depth = depth || 0;

        var spacing = ' '.repeat(depth * 4);

        Object.each(fields, function(field, id) {
            field.id = field.id || id;

            var modelName = parentModelName + '.' + id;

            var line = spacing + '<jarves-field definition="%s">\n'.sprintf(modelName);
            if (field.children) {
                line += this.buildXml(field.children, modelName + '.children', depth + 1);
            }
            line += spacing + '</jarves-field>\n';
            xml.push(line);
        }.bind(this));

        return xml.join("\n");
    }
});