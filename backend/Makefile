CXX = g++
CXXFLAGS = -Wall -Wextra -O2 -std=c++17
LIBS = -lpcap -lmysqlclient

BIN_DIR = bin
TARGET = $(BIN_DIR)/collector
SRC = src/collector.cpp

all: $(BIN_DIR) $(TARGET) $(BIN_DIR)/collector.cfg $(BIN_DIR)/collector.cfg.sample $(BIN_DIR)/setup-dev.backend.sh $(BIN_DIR)/RUN_GUIDE.md

$(BIN_DIR):
	mkdir -p $(BIN_DIR)

$(TARGET): $(SRC)
	$(CXX) $(CXXFLAGS) -o $(TARGET) $(SRC) $(LIBS)

$(BIN_DIR)/collector.cfg: collector.cfg
	cp collector.cfg $(BIN_DIR)/

$(BIN_DIR)/collector.cfg.sample: collector.cfg.sample
	cp collector.cfg.sample $(BIN_DIR)/

$(BIN_DIR)/setup-dev.backend.sh: setup-dev.backend.sh
	cp setup-dev.backend.sh $(BIN_DIR)/
	chmod +x $(BIN_DIR)/setup-dev.backend.sh

$(BIN_DIR)/RUN_GUIDE.md: RUN_GUIDE.md
	cp RUN_GUIDE.md $(BIN_DIR)/

clean:
	rm -rf $(BIN_DIR)

.PHONY: all clean
