import Litepicker from 'litepicker';

type LitepickerOptions = ConstructorParameters<typeof Litepicker>[0];

interface LitepickerDate {
  format: (format: string) => string;
}

type DateRangeInput = HTMLInputElement & {
  dataset: DOMStringMap & {
    nameFrom: string;
    nameTo: string;
  };
};

export default function initDatepickers() {
  document.querySelectorAll<HTMLInputElement>('.datepicker').forEach(element => {
    element.autocomplete = 'off';
    new Litepicker({
      element: element,
      resetButton: true,
      autoRefresh: true,
      format: 'YYYY-MM-DD',
      dropdowns: {
        minYear: 1930,
        months: !!element.dataset.pickMonth,
        years: !!element.dataset.pickYear
      } as LitepickerOptions['dropdowns'],
      singleMode: !isRanger(element),
      delimiter: ' to ',
      // ClientFence core patch: Bootstrap Icons from the sprite instead of an inline Tabler drawing.
      buttonText: {
        previousMonth: '<svg class="icon"><use href="#chevron-left" /></svg>',
        nextMonth: '<svg class="icon"><use href="#chevron-right" /></svg>',
        reset: '<svg class="icon"><use href="#clock-x" /></svg>',
      } as LitepickerOptions['buttonText'],
      setup: (picker) => {
        picker.on('selected', (date: LitepickerDate, dateTo?: LitepickerDate) => {
          if (isRanger(element) && dateTo) {
            setDateToEl(element.dataset.nameFrom, date.format('YYYY-MM-DD'), element);
            setDateToEl(element.dataset.nameTo, dateTo.format('YYYY-MM-DD'), element);
          }
        });
      }
    });

  });
}

function isRanger(element: HTMLInputElement): element is DateRangeInput {
  return Boolean(element.dataset.nameFrom && element.dataset.nameTo);
}

function setDateToEl(name: string, value: string, siblingEl: HTMLElement) {
  let element = document.getElementById(name) as HTMLInputElement | null;
  if (element === null) {
    element = document.createElement('input');
    element.type = 'hidden';
    element.id = name;
    element.name = name;
    element.value = value;
    siblingEl.parentNode!.insertBefore(element, siblingEl.nextSibling);
    return;
  }
  element.value = value;
}
