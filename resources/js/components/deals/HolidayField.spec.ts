import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HolidayField from '@/components/deals/HolidayField.vue';

/**
 * The control is filled one end at a time, but only a whole range narrows
 * anything - so the half-filled range has to survive inside the component.
 * It cannot be read back off the props: those only carry what the server was
 * told, and the server is deliberately told nothing until both ends are known.
 */
const holidayField = (from = '', to = '') =>
    mount(HolidayField, { props: { from, to } });

const pick = async (
    field: ReturnType<typeof holidayField>,
    which: 0 | 1,
    value: string,
) => {
    const input = field.findAll('input[type="date"]')[which];

    // Set the value the way the native picker does, then fire the one event
    // the control listens for - setValue would fire an extra `input` too.
    (input.element as HTMLInputElement).value = value;

    await input.trigger('change');
};

describe('HolidayField', () => {
    it('says nothing while only one end of the range is known', async () => {
        const field = holidayField();

        await pick(field, 0, '2026-09-12');

        // Asking the server now would only clear the filter and, on the way
        // back, wipe the day just picked.
        expect(field.emitted('change')).toBeUndefined();
    });

    it('asks for the whole range once the second day is picked', async () => {
        const field = holidayField();

        await pick(field, 0, '2026-09-12');
        await pick(field, 1, '2026-09-20');

        // The regression: the first day used to be read back off the props,
        // which are still empty at this point, so it was dropped and the
        // filter could never be set from the interface at all.
        expect(field.emitted('change')).toEqual([
            [{ from: '2026-09-12', to: '2026-09-20' }],
        ]);
    });

    it('keeps both days after the server has echoed them back', async () => {
        const field = holidayField('2026-09-12', '2026-09-20');

        await pick(field, 1, '2026-09-22');

        expect(field.emitted('change')).toEqual([
            [{ from: '2026-09-12', to: '2026-09-22' }],
        ]);
    });

    it('drags the end along when the start is moved past it', async () => {
        const field = holidayField('2026-09-12', '2026-09-20');

        await pick(field, 0, '2026-09-24');

        // Picking a start after the existing end can only mean the range is
        // being moved, never that it should run backwards.
        expect(field.emitted('change')).toEqual([
            [{ from: '2026-09-24', to: '2026-09-24' }],
        ]);
    });

    it('clears both ends at once', async () => {
        const field = holidayField('2026-09-12', '2026-09-20');

        await field.find('button').trigger('click');

        expect(field.emitted('change')).toEqual([[{ from: '', to: '' }]]);
    });

    it('offers to clear as soon as one day is picked', async () => {
        const field = holidayField();

        expect(field.find('button').exists()).toBe(false);

        await pick(field, 0, '2026-09-12');

        // Without this the only way out of a half-typed range is the native
        // picker, which has no way to empty the field on every browser.
        expect(field.find('button').exists()).toBe(true);
    });

    it('drops a half-typed range without asking the server', async () => {
        const field = holidayField();

        await pick(field, 0, '2026-09-12');
        await field.find('button').trigger('click');

        expect(
            (field.findAll('input[type="date"]')[0].element as HTMLInputElement)
                .value,
        ).toBe('');

        // Nothing was filtered by, so there is nothing to tell the server.
        expect(field.emitted('change')).toBeUndefined();
    });
});
