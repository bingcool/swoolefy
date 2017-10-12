# Glass Dataset

From USA Forensic Science Service; 6 types of glass; defined in terms of their oxide content (i.e. Na, Fe, K, etc)

### Specification

| Classes               | 6     |
| Samples total         | 214   |
| Features per sample   | 9     |

Samples per class:
 * 70 float processed building windows
 * 17 float processed vehicle windows
 * 76 non-float processed building windows
 * 13 containers
 * 9 tableware
 * 29 headlamps

### Load

To load Glass dataset simple use:

```
use Phpml\Dataset\Demo\Glass;

$dataset = new Glass();
```

### Several samples example

```
RI: refractive index,Na: Sodium,Mg: Magnesium,Al: Aluminum,Si: Silicon,K: Potassium,Ca: Calcium,Ba: Barium,Fe: Iron,type of glass
1.52101,13.64,4.49,1.10,71.78,0.06,8.75,0.00,0.00,building_windows_float_processed
1.51761,13.89,3.60,1.36,72.73,0.48,7.83,0.00,0.00,building_windows_float_processed
1.51618,13.53,3.55,1.54,72.99,0.39,7.78,0.00,0.00,building_windows_float_processed
1.51766,13.21,3.69,1.29,72.61,0.57,8.22,0.00,0.00,building_windows_float_processed
1.51742,13.27,3.62,1.24,73.08,0.55,8.07,0.00,0.00,building_windows_float_processed
1.51596,12.79,3.61,1.62,72.97,0.64,8.07,0.00,0.26,building_windows_float_processed
1.51743,13.30,3.60,1.14,73.09,0.58,8.17,0.00,0.00,building_windows_float_processed
1.51756,13.15,3.61,1.05,73.24,0.57,8.24,0.00,0.00,building_windows_float_processed
1.51918,14.04,3.58,1.37,72.08,0.56,8.30,0.00,0.00,building_windows_float_processed
```
