import { ref, computed, watch } from 'vue';

export interface LabelElementStyles {
    fontSize?: number;
    alignment?: 'left' | 'center' | 'right';
    maxChars?: number;
    barcodeHeight?: number;
    showText?: boolean;
    moduleWidth?: number;
    thickness?: number;
}

export interface LabelElement {
    id: string;
    element_type: 'text_field' | 'barcode' | 'static_text' | 'line';
    x: number;
    y: number;
    width: number;
    height: number;
    content: string | null;
    styles: LabelElementStyles;
    sort_order: number;
}

export interface LabelTemplate {
    id?: number;
    name: string;
    type: 'product' | 'transaction';
    canvas_width: number;
    canvas_height: number;
    is_default: boolean;
    elements: LabelElement[];
}

export interface FieldGroup {
    [key: string]: string;
}

export interface AvailableFields {
    [groupName: string]: FieldGroup;
}

export interface SampleData {
    [group: string]: {
        [field: string]: string | null;
    };
}

const DEFAULT_ELEMENT_STYLES: Record<string, LabelElementStyles> = {
    text_field: { fontSize: 20, alignment: 'left' },
    barcode: { barcodeHeight: 50, showText: true, moduleWidth: 2 },
    static_text: { fontSize: 20, alignment: 'left' },
    line: { thickness: 2 },
};

const DEFAULT_ELEMENT_SIZES: Record<string, { width: number; height: number }> = {
    text_field: { width: 150, height: 25 },
    barcode: { width: 200, height: 60 },
    static_text: { width: 150, height: 25 },
    line: { width: 200, height: 2 },
};

let elementIdCounter = 0;

function generateElementId(): string {
    return `element_${Date.now()}_${++elementIdCounter}`;
}

export function useLabelDesigner() {
    // Template state
    const template = ref<LabelTemplate>({
        name: '',
        type: 'product',
        canvas_width: 406, // 2" at 203 DPI
        canvas_height: 203, // 1" at 203 DPI
        is_default: false,
        elements: [],
    });

    // Selection state
    const selectedElementId = ref<string | null>(null);

    // Drag state
    const isDragging = ref(false);
    const dragOffset = ref({ x: 0, y: 0 });

    // Canvas scale (for display purposes)
    const canvasScale = ref(2);

    // Undo/redo history
    const history = ref<LabelElement[][]>([]);
    const historyIndex = ref(-1);

    // Computed properties
    const selectedElement = computed(() => {
        if (!selectedElementId.value) return null;
        return template.value.elements.find(e => e.id === selectedElementId.value) || null;
    });

    const canUndo = computed(() => historyIndex.value > 0);
    const canRedo = computed(() => historyIndex.value < history.value.length - 1);

    // Initialize template from props
    function initTemplate(data: Partial<LabelTemplate> | null) {
        if (data) {
            template.value = {
                id: data.id,
                name: data.name || '',
                type: data.type || 'product',
                canvas_width: data.canvas_width || 406,
                canvas_height: data.canvas_height || 203,
                is_default: data.is_default || false,
                elements: (data.elements || []).map(e => ({
                    ...e,
                    id: e.id?.toString() || generateElementId(),
                    styles: e.styles || DEFAULT_ELEMENT_STYLES[e.element_type] || {},
                })),
            };
        } else {
            template.value = {
                name: '',
                type: 'product',
                canvas_width: 406,
                canvas_height: 203,
                is_default: false,
                elements: [],
            };
        }
        saveHistory();
    }

    // History management
    function saveHistory() {
        // Remove any future history if we're not at the end
        if (historyIndex.value < history.value.length - 1) {
            history.value = history.value.slice(0, historyIndex.value + 1);
        }

        // Deep clone elements
        history.value.push(JSON.parse(JSON.stringify(template.value.elements)));
        historyIndex.value = history.value.length - 1;

        // Limit history size
        if (history.value.length > 50) {
            history.value.shift();
            historyIndex.value--;
        }
    }

    function undo() {
        if (!canUndo.value) return;
        historyIndex.value--;
        template.value.elements = JSON.parse(JSON.stringify(history.value[historyIndex.value]));
    }

    function redo() {
        if (!canRedo.value) return;
        historyIndex.value++;
        template.value.elements = JSON.parse(JSON.stringify(history.value[historyIndex.value]));
    }

    // Element management
    function addElement(type: LabelElement['element_type'], content?: string) {
        const defaults = DEFAULT_ELEMENT_SIZES[type] || { width: 100, height: 25 };
        const styles = { ...DEFAULT_ELEMENT_STYLES[type] };

        const element: LabelElement = {
            id: generateElementId(),
            element_type: type,
            x: 10,
            y: 10,
            width: defaults.width,
            height: defaults.height,
            content: content || (type === 'static_text' ? 'Label' : null),
            styles,
            sort_order: template.value.elements.length,
        };

        template.value.elements.push(element);
        selectedElementId.value = element.id;
        saveHistory();

        return element;
    }

    function updateElement(id: string, updates: Partial<LabelElement>) {
        const index = template.value.elements.findIndex(e => e.id === id);
        if (index === -1) return;

        template.value.elements[index] = {
            ...template.value.elements[index],
            ...updates,
        };
    }

    function updateElementPosition(id: string, x: number, y: number) {
        const element = template.value.elements.find(e => e.id === id);
        if (!element) return;

        // Clamp to canvas bounds
        element.x = Math.max(0, Math.min(template.value.canvas_width - element.width, x));
        element.y = Math.max(0, Math.min(template.value.canvas_height - element.height, y));
    }

    function updateElementSize(id: string, width: number, height: number) {
        const element = template.value.elements.find(e => e.id === id);
        if (!element) return;

        // Minimum size constraints
        element.width = Math.max(20, width);
        element.height = Math.max(element.element_type === 'line' ? 1 : 15, height);

        // Ensure element stays within canvas
        if (element.x + element.width > template.value.canvas_width) {
            element.x = Math.max(0, template.value.canvas_width - element.width);
        }
        if (element.y + element.height > template.value.canvas_height) {
            element.y = Math.max(0, template.value.canvas_height - element.height);
        }
    }

    function updateElementStyles(id: string, styles: Partial<LabelElementStyles>) {
        const element = template.value.elements.find(e => e.id === id);
        if (!element) return;

        element.styles = { ...element.styles, ...styles };
        saveHistory();
    }

    function deleteElement(id: string) {
        const index = template.value.elements.findIndex(e => e.id === id);
        if (index === -1) return;

        template.value.elements.splice(index, 1);

        if (selectedElementId.value === id) {
            selectedElementId.value = null;
        }

        saveHistory();
    }

    function selectElement(id: string | null) {
        selectedElementId.value = id;
    }

    function duplicateElement(id: string) {
        const element = template.value.elements.find(e => e.id === id);
        if (!element) return;

        const newElement: LabelElement = {
            ...JSON.parse(JSON.stringify(element)),
            id: generateElementId(),
            x: element.x + 20,
            y: element.y + 20,
            sort_order: template.value.elements.length,
        };

        template.value.elements.push(newElement);
        selectedElementId.value = newElement.id;
        saveHistory();

        return newElement;
    }

    // Canvas management
    function setCanvasSize(width: number, height: number) {
        template.value.canvas_width = width;
        template.value.canvas_height = height;

        // Adjust elements that are now outside the canvas
        template.value.elements.forEach(element => {
            if (element.x + element.width > width) {
                element.x = Math.max(0, width - element.width);
            }
            if (element.y + element.height > height) {
                element.y = Math.max(0, height - element.height);
            }
        });

        saveHistory();
    }

    // Drag handling
    function startDrag(elementId: string, offsetX: number, offsetY: number) {
        selectedElementId.value = elementId;
        isDragging.value = true;
        dragOffset.value = { x: offsetX, y: offsetY };
    }

    function drag(canvasX: number, canvasY: number) {
        if (!isDragging.value || !selectedElementId.value) return;

        const x = canvasX - dragOffset.value.x;
        const y = canvasY - dragOffset.value.y;
        updateElementPosition(selectedElementId.value, x, y);
    }

    function endDrag() {
        if (isDragging.value) {
            isDragging.value = false;
            saveHistory();
        }
    }

    // Sample data rendering
    function getFieldValue(fieldKey: string | null, sampleData: SampleData): string {
        if (!fieldKey) return '';

        const [group, field] = fieldKey.split('.');
        if (!group || !field) return fieldKey; // Static text

        return sampleData[group]?.[field] || `[${fieldKey}]`;
    }

    function renderElementContent(element: LabelElement, sampleData: SampleData): string {
        if (element.element_type === 'static_text') {
            return element.content || '';
        }
        if (element.element_type === 'line') {
            return '';
        }
        return getFieldValue(element.content, sampleData);
    }

    // Export for saving
    function getTemplateData(): Omit<LabelTemplate, 'id'> & { id?: number } {
        return {
            id: template.value.id,
            name: template.value.name,
            type: template.value.type,
            canvas_width: template.value.canvas_width,
            canvas_height: template.value.canvas_height,
            is_default: template.value.is_default,
            elements: template.value.elements.map((e, index) => ({
                element_type: e.element_type,
                x: e.x,
                y: e.y,
                width: e.width,
                height: e.height,
                content: e.content,
                styles: e.styles,
                sort_order: index,
            })),
        };
    }

    // Keyboard shortcuts
    function handleKeyDown(event: KeyboardEvent) {
        // Delete selected element
        if ((event.key === 'Delete' || event.key === 'Backspace') && selectedElementId.value) {
            // Don't delete if editing an input
            if ((event.target as HTMLElement).tagName === 'INPUT') return;
            deleteElement(selectedElementId.value);
            event.preventDefault();
        }

        // Undo/Redo
        if (event.metaKey || event.ctrlKey) {
            if (event.key === 'z' && !event.shiftKey) {
                undo();
                event.preventDefault();
            } else if ((event.key === 'z' && event.shiftKey) || event.key === 'y') {
                redo();
                event.preventDefault();
            } else if (event.key === 'd' && selectedElementId.value) {
                duplicateElement(selectedElementId.value);
                event.preventDefault();
            }
        }

        // Arrow keys to move selected element
        if (selectedElementId.value && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
            const element = selectedElement.value;
            if (!element) return;

            const step = event.shiftKey ? 10 : 1;
            let x = element.x;
            let y = element.y;

            switch (event.key) {
                case 'ArrowUp':
                    y -= step;
                    break;
                case 'ArrowDown':
                    y += step;
                    break;
                case 'ArrowLeft':
                    x -= step;
                    break;
                case 'ArrowRight':
                    x += step;
                    break;
            }

            updateElementPosition(selectedElementId.value, x, y);
            event.preventDefault();
        }
    }

    return {
        // State
        template,
        selectedElementId,
        selectedElement,
        isDragging,
        canvasScale,

        // History
        canUndo,
        canRedo,
        undo,
        redo,

        // Template management
        initTemplate,
        getTemplateData,

        // Element management
        addElement,
        updateElement,
        updateElementPosition,
        updateElementSize,
        updateElementStyles,
        deleteElement,
        selectElement,
        duplicateElement,

        // Canvas management
        setCanvasSize,

        // Drag handling
        startDrag,
        drag,
        endDrag,

        // Rendering
        getFieldValue,
        renderElementContent,

        // Keyboard
        handleKeyDown,
    };
}
