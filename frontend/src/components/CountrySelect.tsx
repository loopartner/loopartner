import { countryName } from '@/lib/format';

/** Pays les plus courants des utilisateurs (France et UE d'abord, décision 12), codes ISO 3166-1. */
const COUNTRIES = ['FR', 'BE', 'LU', 'CH', 'MC', 'DE', 'AT', 'ES', 'IT', 'PT', 'NL', 'IE', 'PL', 'SE', 'DK', 'FI', 'GR', 'CZ', 'RO', 'GB', 'CA'];

export function CountrySelect({ id, label, value, onChange, error, allowEmpty = false }: { id: string; label: string; value: string; onChange: (value: string) => void; error?: string; allowEmpty?: boolean }) {
  const options = COUNTRIES.includes(value) || !value ? COUNTRIES : [value, ...COUNTRIES];
  return (
    <div className="field">
      <label className="field__label" htmlFor={id}>
        {label}
      </label>
      <select id={id} className="select" value={value} onChange={(event) => onChange(event.target.value)}>
        {allowEmpty ? <option value="">Non précisé</option> : null}
        {options.map((code) => (
          <option key={code} value={code}>
            {countryName(code)}
          </option>
        ))}
      </select>
      {error ? <span className="field__error">{error}</span> : null}
    </div>
  );
}
