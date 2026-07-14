# MiniMAGUX Network Traffic Monitor - 실행 안내서 (Run Guide)

본 문서는 Rocky Linux 10 환경에서 MiniMAGUX C++ 패킷 수집기(`collector`)를 직접 기동하고, 중지하고, 수집된 결과를 확인하는 방법에 대한 핵심 요약 안내서입니다.

---

## 📂 파일 위치 안내

원격 서버에 컴파일된 수집기 및 설정 파일은 아래 경로에 위치해 있습니다.
- **실행 디렉토리**: `/root/MiniMAGUX/backend/bin/`
- **주요 파일**:
  - `collector` : 패킷 수집 실행 바이너리 (데몬)
  - `collector.cfg` : 수집기 설정 파일 (인터페이스 및 동작 모드 정의)
  - `csv_logs/` : 수집된 CSV 로그 파일들이 시간별로 적재되는 디렉토리

---

## ⚙️ 설정 파일 (`collector.cfg`) 수정 방법

수집기를 실행하기 전에 `collector.cfg` 파일의 네트워크 인터페이스가 맞게 설정되어 있는지 확인합니다.
```bash
vi /root/MiniMAGUX/backend/bin/collector.cfg
```

### [핵심 설정 항목]
- **`interface`**: 패킷을 감시할 리눅스 이더넷 카드 이름 (예: `enp0s3`)
  *(※ `ip link` 또는 `ip addr` 명령어로 실제 카드의 이름을 확인할 수 있습니다. 리눅스에서는 `any` 대신 실제 디바이스명을 쓰는 것이 정확한 파싱에 유리합니다.)*
- **`csv_dir`**: CSV 로그가 저장될 폴더 경로 (기본값: `./csv_logs`)
- **`use_manticore`**: DB 연동 없이 CSV 파일만 생성하고자 하는 경우 `false`로 설정합니다. (현재 기본값 `false`)

---

## 🏃 실행 및 관리 명령어

모든 수집 및 캡처 작업은 네트워크 카드 제어 권한이 필요하므로 반드시 **`root` 권한(`sudo`)**으로 실행해야 합니다.

### 1. 패킷 수집기 데몬 기동
설정 파일(`collector.cfg`)에 정의된 인터페이스를 사용하여 백그라운드 수집 데몬을 실행합니다.
```bash
cd /root/MiniMAGUX/backend/bin
sudo ./collector
```

### 2. 특정 인터페이스 임시 지정 실행 (오버라이드)
설정 파일을 수정하지 않고, 다른 네트워크 카드로 즉시 수집하려면 실행 인자로 인터페이스명을 명시합니다.
```bash
sudo ./collector enp0s3
```

### 3. 수집기 실행 상태 확인 (프로세스 확인)
데몬이 원활하게 돌고 있는지 프로세스 테이블을 검사합니다.
```bash
ps aux | grep collector
```
*(성공적으로 실행 중이라면 `collector` 단어가 포함된 백그라운드 프로세스가 리스트에 출력됩니다.)*

### 4. 수집기 데몬 안전 종료 (Graceful Kill)
수집 프로세스를 정상 종료하고 메모리에 보관 중이던 마지막 패킷들을 파일로 완벽하게 쓰기(Flush) 위해 아래 종료 옵션을 실행합니다.
```bash
cd /root/MiniMAGUX/backend/bin
sudo ./collector -kill
```
*(※ `kill -9` 등으로 강제 종료할 경우, 10초 이내에 유입된 버퍼 데이터가 유실될 수 있으므로 반드시 `-kill` 옵션 사용을 권장합니다.)*

---

## 📊 수집 로그 실시간 확인 방법

수집기가 실행 중일 때, 패킷 데이터가 파일에 잘 쌓이고 있는지 다음과 같은 방법으로 실시간 체크할 수 있습니다.

### 1. 실시간 패킷 출력 모니터링 (Tail)
시간 단위로 로테이션되는 CSV 로그 파일에 기록되는 패킷 정보들을 실시간으로 화면에 터미널로 뿌려봅니다.
```bash
tail -f /root/MiniMAGUX/backend/bin/csv_logs/traffic_*.csv
```

### 2. 패킷 유입 라인 카운트 모니터링 (Watch)
수집되는 패킷 행 수가 매초 얼마나 증가하는지 계기판처럼 숫자로 확인합니다.
```bash
watch -n 1 "wc -l /root/MiniMAGUX/backend/bin/csv_logs/traffic_*.csv"
```
